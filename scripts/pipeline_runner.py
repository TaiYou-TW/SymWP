#!/usr/bin/env python3

import os
import re
import shutil
import subprocess
import sys
import signal
import time
import argparse

from pathlib import Path
from subprocess import TimeoutExpired, CalledProcessError

HARNESS_GEN_SCRIPT = "harness_generator.php"
XSS_CHECKER = "XSSChecker.php"
SQLI_CHECKER = "SQLiChecker.php"

S2E_BOOTSTRAP_TEMPLATE_PATH = "bootstrap_template.sh"
S2E_COMMAND = "s2e"
OBJDUMP_COMMAND = "objdump"

S2E_PROJECTS_DIR = "projects"
HARNESS_DIR = ".harness/symbolic"
OUTPUT_DIR = "SymWP"

XSS_PAYLOAD_MARKER = "XSS_PAYLOAD_MARKER"

FATAL_ERROR_THRESHOLD = 10000

CHECK_INTERVAL_SECONDS = 10

ENV_SYMWP_PHP = "SYMWP_PHP"

ECHO_FUNCTION_TRACKER_ADDRESS = ""
SQLITE_FUNCTION_TRACKER_ADDRESS = ""

STOP_IF_FOUND = False
ITERATIONS = 1
USE_WP_LOADER = False


def parse_args() -> None:
    """
    Parse command line arguments for the script.
    Sets global variables for timeout, argv length, and core count.
    """
    global TIMEOUT_MINUTES, ARGV_LENGTH, CORE, INCLUDE, STOP_IF_FOUND, ITERATIONS, USE_WP_LOADER

    parser = argparse.ArgumentParser(
        description="Run symbolic & dynamic analysis on a WordPress plugin."
    )
    parser.add_argument("plugin_folder", help="Path to the WordPress plugin folder.")
    parser.add_argument(
        "--timeout",
        "-t",
        type=int,
        default=30,
        help="S2E timeout in minutes (default: 30).",
    )
    parser.add_argument(
        "--argv-length",
        "-l",
        type=int,
        default=20,
        help="Length of symbolic argv (default: 20).",
    )
    parser.add_argument(
        "--core",
        "-c",
        type=int,
        default=16,
        help="Number of cores to use for S2E (default: 16).",
    )
    parser.add_argument(
        "--include",
        "-i",
        type=str,
        default="",
        help="Include only specific targets, it can be filename, full path of file or method name.",
    )
    parser.add_argument(
        "--stop-if-found",
        action="store_true",
        help="Stop S2E analysis early if vulnerabilities are found.",
    )
    parser.add_argument(
        "--iterations",
        type=int,
        default=1,
        help="Number of times to run the whole analysis (default: 1).",
    )
    parser.add_argument(
        "--use-wp-loader",
        action="store_true",
        help="Use original wp-loader.php instead of custom loaders.",
    )

    args = parser.parse_args()
    TIMEOUT_MINUTES = args.timeout
    ARGV_LENGTH = args.argv_length
    CORE = args.core
    INCLUDE = args.include.replace("/", "-").replace(".", "-")
    STOP_IF_FOUND = args.stop_if_found
    ITERATIONS = args.iterations
    USE_WP_LOADER = args.use_wp_loader


def is_all_dependencies_present() -> bool:
    """
    Check if all required dependencies are present.
    Returns True if all dependencies are found, otherwise False.
    """
    global PHP_EXECUTABLE
    dependencies = [
        HARNESS_GEN_SCRIPT,
        XSS_CHECKER,
        SQLI_CHECKER,
        S2E_BOOTSTRAP_TEMPLATE_PATH,
    ]

    is_all_present = True
    for dep in dependencies:
        if not Path(dep).exists():
            print(f"[-] Missing dependency: {dep}")
            is_all_present = False

    commands = [
        [S2E_COMMAND],
        [OBJDUMP_COMMAND, "-v"],
    ]
    for command in commands:
        try:
            subprocess.run(
                command,
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except FileNotFoundError:
            print(f"[-] Missing executable: {command}")
            is_all_present = False

    environment_vars = [
        ENV_SYMWP_PHP,
    ]
    for var in environment_vars:
        if var not in os.environ:
            print(f"[-] Environment variable {var} is not set.")
            is_all_present = False

    PHP_EXECUTABLE = os.getenv(ENV_SYMWP_PHP)

    return is_all_present


def generate_harnesses(plugin_folder: str) -> None:
    """
    Generate harnesses for the given plugin folder using the harness generator script.
    Args:
        plugin_folder (str): Path to the WordPress plugin folder.
    """
    print(f"[+] Generating harnesses for: {plugin_folder}")
    cmd = [PHP_EXECUTABLE, HARNESS_GEN_SCRIPT, plugin_folder]
    if USE_WP_LOADER:
        cmd.append("--use-wp-loader")
    subprocess.run(cmd, check=True)


def get_argv_count(harness_path: str) -> int:
    """
    Get the number of symbolic arguments expected by the harness.
    Args:
        harness_path (str): Path to the harness file.
    Returns:
        int: Number of symbolic arguments.
    """
    with open(harness_path) as f:
        content = f.read()
    matches = re.findall(r"\$argv\[(\d+)\]", content)
    return max(map(int, matches)) + 1 if matches else 0


def get_function_addresses() -> None:
    """
    Get the addresses of the functions to be monitored by the plugins.
    This function uses objdump to find the addresses of specific functions in the PHP binary.
    """
    global ECHO_FUNCTION_TRACKER_ADDRESS, SQLITE_FUNCTION_TRACKER_ADDRESS

    try:
        output = subprocess.check_output(
            [OBJDUMP_COMMAND, "-d", PHP_EXECUTABLE], text=True
        )
        for line in output.splitlines():
            if "php_output_write>:" in line:
                ECHO_FUNCTION_TRACKER_ADDRESS = line.split()[0]
            elif "sqlite_handle_preparer>:" in line:
                SQLITE_FUNCTION_TRACKER_ADDRESS = line.split()[0]
    except CalledProcessError as e:
        print(f"[-] Error getting function addresses: {e}")

    if not ECHO_FUNCTION_TRACKER_ADDRESS or not SQLITE_FUNCTION_TRACKER_ADDRESS:
        print(
            "[-] Could not find function addresses for EchoFunctionTracker or SqliteFunctionTracker."
        )
        sys.exit(1)


def setup_s2e_project(
    plugin_name: str, harness_path: str, argv_count: int, project_name: str
) -> None:
    """
    Set up a new S2E project with the given harness and symbolic arguments.
    Args:
        plugin_name (str): Name of the plugin.
        harness_path (str): Path to the harness file.
        argv_count (int): Number of symbolic arguments.
        project_name (str): Name of the S2E project.
    """
    global ECHO_FUNCTION_TRACKER_ADDRESS, SQLITE_FUNCTION_TRACKER_ADDRESS

    print(f"[+] Setting up S2E project for {project_name}...")
    proj_path = Path(S2E_PROJECTS_DIR) / project_name

    # Run S2E command to new project
    print(f"[+] Generating new project...")
    subprocess.run(
        [
            S2E_COMMAND,
            "new_project",
            "-f",
            "-n",
            project_name,
            PHP_EXECUTABLE,
            harness_path,
        ],
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    print(f"[+] Rewriting configs...")
    bootstrap_path = proj_path / "bootstrap.sh"
    shutil.copy(S2E_BOOTSTRAP_TEMPLATE_PATH, bootstrap_path)
    with open(bootstrap_path, "r") as f:
        lines = f.readlines()

    sym_args = " ".join(str(i) for i in range(2, argv_count + 1))
    new_lines = []
    for line in lines:
        if "S2E_SYM_ARGS=" in line:
            new_lines.append(
                line.replace('S2E_SYM_ARGS=""', f'S2E_SYM_ARGS="{sym_args}"')
            )
        elif 'execute "${TARGET_PATH}"' in line:
            new_lines.append(
                line.replace("\n", "")
                + " "
                + " ".join("a" * ARGV_LENGTH for i in range(argv_count - 1))
                + "\n"
            )
        elif "# Plugin" in line:
            new_lines.append(line)
            new_lines.append(f'${{S2ECMD}} get "{plugin_name}.tar.gz"\n')
            new_lines.append(f"tar -xzf {plugin_name}.tar.gz\n")
        else:
            new_lines.append(line)

    with open(bootstrap_path, "w") as f:
        f.writelines(new_lines)

    if not ECHO_FUNCTION_TRACKER_ADDRESS or not SQLITE_FUNCTION_TRACKER_ADDRESS:
        get_function_addresses()

    # enable plugins in s2e-config.lua
    s2e_config_path = proj_path / "s2e-config.lua"
    with open(s2e_config_path, "a") as f:
        f.write('\nadd_plugin("FunctionMonitor")\n')
        f.write(
            f'add_plugin("EchoFunctionTracker")\npluginsConfig.EchoFunctionTracker = {{\n    addressToTrack = 0x{ECHO_FUNCTION_TRACKER_ADDRESS},\n}}\n'
        )
        f.write(
            f'add_plugin("SqliteFunctionTracker")\npluginsConfig.SqliteFunctionTracker = {{\n    addressToTrack = 0x{SQLITE_FUNCTION_TRACKER_ADDRESS},\n}}\n'
        )

    print(f"[+] Copying files...")
    plugin_zip = f"{plugin_name}.tar.gz"
    if not Path(plugin_zip).exists():
        shutil.make_archive(plugin_name, "gztar", "./", plugin_name)
    shutil.copy(f"{plugin_name}.tar.gz", proj_path)

    wordpress_zip = "WordPress.tar.gz"
    if not Path(wordpress_zip).exists():
        shutil.make_archive("WordPress", "gztar", "./", "WordPress")
    shutil.copy(wordpress_zip, proj_path)

    harness_dest = proj_path / "harness.php"
    shutil.copy(harness_path, harness_dest)
    shutil.move(
        f"{proj_path}/{Path(harness_path).name}.symranges",
        f"{proj_path}/harness.symranges",
    )

    if not USE_WP_LOADER:
        shutil.copy("base-wordpress-loader.php", proj_path)
        shutil.copy("symbolic-wordpress-loader.php", proj_path)
        shutil.copy("concrete-wordpress-loader.php", proj_path)


def run_s2e(
    project_name: str, project_path: str, harness_path: str = None
) -> tuple[bool, float]:
    """
    Run the S2E analysis on the specified project.
    Args:
        project_name (str): Name of the S2E project.
        project_path (Path): Path to the S2E project directory.
        harness_path (str): Path to the harness file (needed for early stopping).
    Returns:
        tuple[bool, float]: (True if stopped early due to vulnerability, time-to-bug in seconds)
    """
    print(f"[+] Running S2E on {project_name}...")

    start_time = time.time()
    now = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime())
    end = time.strftime(
        "%Y-%m-%d %H:%M:%S", time.localtime(time.time() + TIMEOUT_MINUTES * 60)
    )
    print(f"[+] Start at: {now}, Estimated end at: {end}")

    early_stop = False
    time_to_bug = 0.0
    try:
        with open(str(project_path) + "/stdout.txt", "w") as f:
            proc = subprocess.Popen(
                [
                    S2E_COMMAND,
                    "run",
                    "-n",
                    "-t",
                    str(TIMEOUT_MINUTES),
                    "-c",
                    str(CORE),
                    project_name,
                ],
                stdout=f,
                stderr=subprocess.DEVNULL,
                preexec_fn=os.setsid,  # Ensure we can kill the process group
            )

            # If stop-if-found is enabled, monitor logs periodically
            if STOP_IF_FOUND and harness_path:
                timeout_end = time.time() + TIMEOUT_MINUTES * 60

                while time.time() < timeout_end:
                    # Check if process is still running
                    if proc.poll() is not None:
                        break

                    # Wait for check interval or process completion
                    try:
                        proc.wait(timeout=CHECK_INTERVAL_SECONDS)
                        break  # Process completed normally
                    except TimeoutExpired:
                        # Process still running, check for vulnerabilities
                        if check_for_vulnerabilities_during_execution(
                            str(project_path), harness_path
                        ):
                            time_to_bug = time.time() - start_time
                            print(
                                f"[!] Stopping S2E analysis due to vulnerability found."
                            )
                            print(
                                f"[!] Time-to-bug: {time_to_bug:.2f} seconds ({time_to_bug/60:.2f} minutes)"
                            )
                            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                            early_stop = True
                            break

                # Final timeout check
                if time.time() >= timeout_end and proc.poll() is None:
                    os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            else:
                # S2E's timeout is not accurate, so we need to make sure it's not running too long
                proc.wait(timeout=TIMEOUT_MINUTES * 60)

    except TimeoutExpired:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
    except KeyboardInterrupt:
        print("[-] User interrupted the process.")
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)

    return early_stop, time_to_bug


def remove_incomplete_args(args: set) -> set:
    """
    Logs may not complete, delete those not have enough args.
    Args:
        args (set): Set of tuples containing symbolic arguments.
    Returns:
        set: Filtered set of tuples with only complete arguments.
    """
    max = -1
    if len(args) > 0:
        for arg in args:
            if len(arg) > max:
                max = len(arg)
        args = [arg for arg in args if len(arg) == max]

    return args


def extract_symbolic_args(project_path: str) -> dict | None:
    """
    Extract symbolic arguments from S2E output logs.
    Args:
        project_path (str): Path to the S2E project directory.
    Returns:
        dict: Dictionary containing sets of symbolic arguments for XSS and SQLi.
    """
    xss_args = set()
    sqli_args = set()
    in_error = False
    error_counter = 0

    for log_file in Path(project_path).rglob("stdout.txt"):
        with open(log_file, "r", errors="ignore") as f:
            for line in f:
                if "Fatal error" in line:
                    error_counter += 1
                    in_error = True

                    """
                    There may have some fatal error during symbolic execution.
                    The "possible" resason is that concurrent execution of S2E
                    may cause I/O errors. So, we only stop the analysis if the
                    number of fatal errors exceeds a threshold.
                    """
                    if error_counter >= FATAL_ERROR_THRESHOLD:
                        print("[-] Too many fatal errors, stopping analysis.")
                        return None

                    continue

                xss_matches = re.findall(
                    r"v\d+_arg\d+_\d+(?:\(exploitable\))? = {[^}]*}; \(string\) \"([^)]*)\"",
                    line,
                )
                if xss_matches and "EchoFunctionTracker: Test case:" in line:
                    exploitable_indexes = re.findall(
                        r"v(\d+)_arg\d+_\d+(?:\(exploitable\))", line
                    )
                    for index in exploitable_indexes:
                        if int(index) < len(xss_matches):
                            xss_matches[int(index)] = XSS_PAYLOAD_MARKER
                    xss_args.add(tuple(xss_matches))
                    continue

                sqli_matches = re.findall(
                    r"v\d+_arg\d+_\d+ = {[^}]*}; \(string\) \"([^)]*)\"", line
                )
                if sqli_matches and "SqliteFunctionTracker: Test case:" in line:
                    sqli_args.add(tuple(sqli_matches))
                    continue

    xss_args = remove_incomplete_args(xss_args)
    sqli_args = remove_incomplete_args(sqli_args)

    return {
        "xss": xss_args,
        "sqli": sqli_args,
    }


def run_dynamic_checker(harness_path: str, symbolic_args: dict) -> str:
    """
    Run dynamic analysis on the harness using symbolic arguments.
    Args:
        harness_path (str): Path to the harness file.
        symbolic_args (dict): Dictionary containing sets of symbolic arguments for XSS and SQLi.
    Returns:
        str: Result of the dynamic analysis.
    """
    print(
        f"[+] Running dynamic analysis on {Path(harness_path).name} with symbolic args: {symbolic_args}"
    )
    result = ""
    if "xss" in symbolic_args and len(symbolic_args["xss"]) > 0:
        result = "[+] XSSChecker:\n"
        for arg in symbolic_args["xss"]:
            result += f"[*] Testing {arg}\n"
            try:
                result += subprocess.run(
                    [PHP_EXECUTABLE, XSS_CHECKER, harness_path, *arg],
                    capture_output=True,
                    text=True,
                    check=True,
                ).stdout
            except:
                result += "Error running XSS_CHECKER\n"
    else:
        result += "[-] No XSS arguments found.\n"

    if "sqli" in symbolic_args and len(symbolic_args["sqli"]) > 0:
        result += "[+] SQLiChecker:\n"
        for arg in symbolic_args["sqli"]:
            result += f"[*] Testing {arg}\n"
            try:
                result += subprocess.run(
                    [PHP_EXECUTABLE, SQLI_CHECKER, harness_path, *arg],
                    capture_output=True,
                    text=True,
                    check=True,
                ).stdout
            except:
                result += "Error running SQLiChecker\n"
    else:
        result += "[-] No SQLi arguments found.\n"

    return result


def has_vulnerability(dynamic_result: str) -> bool:
    """
    Check if the dynamic analysis result contains actual vulnerabilities.
    Args:
        dynamic_result (str): Output from the dynamic checker.
    Returns:
        bool: True if vulnerabilities are found, False otherwise.
    """
    vulnerability_patterns = [
        "[!] Potential quotes breaks in tags detected",
        "[!] Potential space breaks in tag without quotes detected",
        "[!] Potential tags injection detected",
        "[!] Potential SQL injection detected",
    ]

    for pattern in vulnerability_patterns:
        if pattern in dynamic_result:
            return True

    return False


def check_for_vulnerabilities_during_execution(
    project_path: str, harness_path: str
) -> bool:
    """
    Check for vulnerabilities during S2E execution by monitoring logs.
    Args:
        project_path (str): Path to the S2E project directory.
        harness_path (str): Path to the harness file.
    Returns:
        bool: True if vulnerabilities are found, False otherwise.
    """
    symbolic_args = extract_symbolic_args(project_path)
    if symbolic_args is None:
        return False

    # Check if we have any test cases
    if not symbolic_args.get("xss", []) and not symbolic_args.get("sqli", []):
        return False

    # Run dynamic checker on concrete harness
    concrete_harness_path = harness_path.replace("/symbolic/", "/concrete/")
    if not Path(concrete_harness_path).exists():
        return False

    dynamic_result = run_dynamic_checker(concrete_harness_path, symbolic_args)

    # Check if actual vulnerabilities were found
    if has_vulnerability(dynamic_result):
        print(f"[!] Vulnerability found! Stopping S2E analysis early.")
        print(dynamic_result)
        return True

    return False


def main():
    global INCLUDE

    parse_args()

    if not is_all_dependencies_present():
        print(
            "[-] Some dependencies are missing. Please check the required scripts and PHP executable."
        )
        sys.exit(1)

    plugin_folder = sys.argv[1]
    plugin_folder_path = Path(plugin_folder)
    plugin_name = plugin_folder_path.name
    harness_dir = plugin_folder_path / HARNESS_DIR

    if not plugin_folder_path.exists():
        print(f"[-] Plugin folder {plugin_folder} does not exist.")
        sys.exit(1)

    generate_harnesses(plugin_folder)

    if not harness_dir.exists():
        os.makedirs(harness_dir)

    if not Path(OUTPUT_DIR).exists():
        os.makedirs(OUTPUT_DIR)

    harnesses = list((harness_dir).rglob("*.php"))
    print(f"[+] {len(harnesses)} harnesses generated in {harness_dir}")

    # Run analysis for specified number of iterations
    for iteration in range(1, ITERATIONS + 1):
        print(f"\n[+] ======= ITERATION {iteration}/{ITERATIONS} =======")

        # Create output directory for this iteration
        current_output_dir = OUTPUT_DIR
        if ITERATIONS > 1:
            current_output_dir = f"{OUTPUT_DIR}/iteration_{iteration}"
            if not Path(current_output_dir).exists():
                os.makedirs(current_output_dir)

        iteration_stopped_early = False

        for harness in harnesses:
            harness_path = str(harness)
            concrete_harness_path = harness_path.replace("/symbolic/", "/concrete/")
            project_name = f"{plugin_name}_{harness.stem}"
            if ITERATIONS > 1:
                project_name = f"{plugin_name}_{harness.stem}_iter{iteration}"
            argv_count = get_argv_count(harness_path)

            if INCLUDE and INCLUDE not in harness_path:
                print(
                    f'[-] Skipping {harness_path} as it does not match the include "{INCLUDE}".'
                )
                continue

            if argv_count == 0:
                print(f"[-] No symbolic arguments found in {harness_path}. Skipping.")
                continue
            print(f"[+] Harness: {harness_path}, Symbolic argv count: {argv_count}")

            setup_s2e_project(plugin_name, harness_path, argv_count, project_name)
            project_path = Path(S2E_PROJECTS_DIR) / project_name
            early_stopped, time_to_bug = run_s2e(
                project_name, project_path, harness_path
            )

            # Save time-to-bug information if early stopping occurred
            if early_stopped and STOP_IF_FOUND:
                print(
                    "[!] Early stopping enabled and vulnerability found. Stopping analysis of remaining harnesses."
                )
                with open(
                    f"{current_output_dir}/{Path(harness_path).name}.time_to_bug", "w"
                ) as f:
                    f.write(
                        f"Time-to-bug: {time_to_bug:.2f} seconds ({time_to_bug/60:.2f} minutes)\n"
                    )
                    f.write(f"Harness: {harness_path}\n")
                    f.write(f"Project: {project_name}\n")
                    f.write(f"Iteration: {iteration}\n")
                iteration_stopped_early = True
                break

            symbolic_args = extract_symbolic_args(project_path)
            if symbolic_args is None:
                break

            result = run_dynamic_checker(concrete_harness_path, symbolic_args)
            with open(f"{current_output_dir}/{Path(harness_path).name}.args", "w") as f:
                f.write("XSS: ")
                f.write(", ".join(str(arg) for arg in symbolic_args["xss"]))
                f.write("\nSQLi: ")
                f.write(", ".join(str(arg) for arg in symbolic_args["sqli"]))
            with open(
                f"{current_output_dir}/{Path(harness_path).name}.dynamic", "w"
            ) as f:
                f.write(result)

            print(result)

        if iteration_stopped_early:
            print(
                f"[!] Iteration {iteration} stopped early due to vulnerability found."
            )
        else:
            print(f"[+] Iteration {iteration} completed normally.")

    print(f"\n[+] All {ITERATIONS} iteration(s) completed.")


if __name__ == "__main__":
    main()
