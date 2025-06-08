import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
from subprocess import TimeoutExpired

HARNESS_GEN_SCRIPT = "harness_generator.php"
XSS_CHECKER = "XSSChecker.php"
SQLI_CHECKER = "SQLiChecker.php"

S2E_BOOTSTRAP_TEMPLATE_PATH = "bootstrap_template.sh"
PHP_PATH = "../php-src/sapi/cli/php"

S2E_TEMPLATE_DIR = "s2e_template"
S2E_PROJECTS_DIR = "projects"
HARNESS_DIR = ".harness"
OUTPUT_DIR = "SymWP"

XSS_PAYLOAD_MARKER = 'XSS_PAYLOAD_MARKER'

TIMEOUT_MINUTES = 30
ARGV_LENGTH = 20
CORE = 16

def is_all_dependencies_present():
    dependencies = [
        HARNESS_GEN_SCRIPT,
        XSS_CHECKER,
        SQLI_CHECKER,
        S2E_BOOTSTRAP_TEMPLATE_PATH,
        PHP_PATH,
    ]

    is_all_present = True
    for dep in dependencies:
        if not Path(dep).exists():
            print(f"[-] Missing dependency: {dep}")
            is_all_present = False

    return is_all_present


def generate_harnesses(plugin_folder):
    print(f"[+] Generating harnesses for: {plugin_folder}")
    subprocess.run(["php", HARNESS_GEN_SCRIPT, plugin_folder], check=True)

def get_argv_count(harness_path):
    with open(harness_path) as f:
        content = f.read()
    matches = re.findall(r'\$argv\[(\d+)\]', content)
    return max(map(int, matches)) + 1 if matches else 0

def setup_s2e_project(plugin_name, harness_path, argv_count, project_name):
    print(f"[+] Setting up S2E project for {project_name}...")
    proj_path = Path(S2E_PROJECTS_DIR) / project_name
    
    # Run S2E command to new project
    print(f"[+] Generating new project...")
    subprocess.run(
        ["s2e", "new_project","-f", "-n", project_name, PHP_PATH, harness_path],
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
            new_lines.append(line.replace("S2E_SYM_ARGS=\"\"", f"S2E_SYM_ARGS=\"{sym_args}\""))
        elif "execute \"${TARGET_PATH}\"" in line:
            new_lines.append(line.replace('\n', '') + f" {' '.join(f'a' * ARGV_LENGTH for i in range(argv_count-1))}\n")
        elif "# Plugin" in line:
            new_lines.append(line)
            new_lines.append(f"${{S2ECMD}} get \"{plugin_name}.tar.gz\"\n")
            new_lines.append(f"tar -xzf {plugin_name}.tar.gz\n")
        else:
            new_lines.append(line)

    with open(bootstrap_path, "w") as f:
        f.writelines(new_lines)

    # enable plugins in s2e-config.lua
    s2e_config_path = proj_path / "s2e-config.lua"
    with open(s2e_config_path, 'a') as f:
        f.write('\nadd_plugin("FunctionMonitor")\n')
        f.write('add_plugin("EchoFunctionTracker")\npluginsConfig.EchoFunctionTracker = {\n    addressToTrack = 0xb4b2e3,\n}\n')
        f.write('add_plugin("SqliteFunctionTracker")\npluginsConfig.SqliteFunctionTracker = {\n    addressToTrack = 0x8bf782,\n}\n')

    print(f"[+] Copying files...")
    plugin_zip = f"{plugin_name}.tar.gz"
    if not Path(plugin_zip).exists():
        shutil.make_archive(plugin_name, 'gztar', './', plugin_name)
    shutil.copy(f"{plugin_name}.tar.gz", proj_path)

    wordpress_zip = 'WordPress.tar.gz'
    if not Path(wordpress_zip).exists():
        shutil.make_archive('WordPress', 'gztar', './', 'WordPress')
    shutil.copy(wordpress_zip, proj_path)

    harness_dest = proj_path / "harness.php"
    shutil.copy(harness_path, harness_dest)
    shutil.move(f"{proj_path}/{Path(harness_path).name}.symranges", f"{proj_path}/harness.symranges")

    shutil.copy("wordpress-loader.php", proj_path)

def run_s2e(project_name, project_path):
    print(f"[+] Running S2E on {project_name}...")
    try:
        with open(str(project_path) + '/stdout.txt', 'w') as f:
            subprocess.run(
                ["s2e", "run", '-n', '-t', str(TIMEOUT_MINUTES), '-c', str(CORE), project_name],
                stdout=f, 
                stderr=subprocess.DEVNULL,
                # S2E's timeout option will not work sometime, make sure it will finish in time
                timeout=TIMEOUT_MINUTES * 60,
            )
    except TimeoutExpired:
        pass

# logs may not complete, delete those not have enough args
def remove_incomplete_args(args):
    max = -1
    if (len(args) > 0):
        for arg in args:
            if len(arg) > max:
                max = len(arg)
        args = [arg for arg in args if len(arg) == max]
    
    return args

def extract_symbolic_args(project_path):
    print("[+] Analyzing S2E output...")
    xss_args = set()
    sqli_args = set()
    for log_file in Path(project_path).rglob("stdout.txt"):
        with open(log_file, "r", errors='ignore') as f:
            for line in f:
                xss_matches = re.findall(r'v\d+_arg\d+_\d+(?:\(exploitable\))? = {[^}]*}; \(string\) "([^)]*)"', line)
                if xss_matches and 'EchoFunctionTracker: Test case:' in line:
                    exploitable_indexes = re.findall(r'v(\d+)_arg\d+_\d+(?:\(exploitable\))', line)
                    for index in exploitable_indexes:
                        if int(index) < len(xss_matches):
                            xss_matches[int(index)] = XSS_PAYLOAD_MARKER
                    xss_args.add(tuple(xss_matches))

                sqli_matches = re.findall(r'v\d+_arg\d+_\d+ = {[^}]*}; \(string\) "([^)]*)"', line)
                if sqli_matches and 'SqliteFunctionTracker: Test case:' in line:
                    sqli_args.add(tuple(sqli_matches))

    xss_args = remove_incomplete_args(xss_args)
    sqli_args = remove_incomplete_args(sqli_args)
    
    return {
        'xss': xss_args,
        'sqli': sqli_args,
    }

# TODO: use symbolic_args after implementation of XSS_CHECKER
def run_dynamic_checker(harness_path, symbolic_args):
    print(f"[+] Running dynamic analysis on {Path(harness_path).name} with symbolic args: {symbolic_args}")
    result = ''
    if len(symbolic_args['xss']) > 0:
        result = '[+] XSSChecker:\n'
        for arg in symbolic_args['xss']:
            result += f"[*] Testing {arg}\n"
            try:
                result += subprocess.run(["php", XSS_CHECKER, harness_path, *arg], capture_output=True, text=True, check=True).stdout
            except:
                result += "Error running XSS_CHECKER\n"
    else:
        result += "[-] No XSS arguments found.\n"

    if len(symbolic_args['sqli']) > 0:
        result += '[+] SQLiChecker:\n'
        for arg in symbolic_args['sqli']:
            result += f"[*] Testing {arg}\n"
            try:
                result += subprocess.run(["php", SQLI_CHECKER, harness_path, *arg], capture_output=True, text=True, check=True).stdout
            except:
                result += "Error running SQLiChecker\n"
    else:
        result += "[-] No SQLi arguments found.\n"

    return result

def main():
    if len(sys.argv) != 2:
        print(f"Usage: python {sys.argv[0]} <plugin_folder>")
        sys.exit(1)

    if not is_all_dependencies_present():
        print("[-] Some dependencies are missing. Please check the required scripts and PHP executable.")
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

    harnesses = list((harness_dir).rglob('*.php'))
    for harness in harnesses:
        try:
            harness_path = str(harness)
            project_name = f"{plugin_name}_{harness.stem}"
            argv_count = get_argv_count(harness_path)

            setup_s2e_project(plugin_name, harness_path, argv_count, project_name)
            project_path = Path(S2E_PROJECTS_DIR) / project_name
            run_s2e(project_name, project_path)

            symbolic_args = extract_symbolic_args(project_path)
            if not symbolic_args:
                print("[-] No symbolic arguments detected.")
                continue

            result = run_dynamic_checker(harness_path, symbolic_args)
            with open(f"{OUTPUT_DIR}/{Path(harness_path).name}.args", 'w') as f:
                f.write('XSS: ')
                f.write(', '.join(str(arg) for arg in symbolic_args['xss']))
                f.write('\nSQLi: ')
                f.write(', '.join(str(arg) for arg in symbolic_args['sqli']))
            with open(f"{OUTPUT_DIR}/{Path(harness_path).name}.dynamic", 'w') as f:
                f.write(result)
            print(result)
        except KeyboardInterrupt:
            print("[-] User interrupted the process.")
            continue

if __name__ == "__main__":
    main()
