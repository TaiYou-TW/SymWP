import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
from subprocess import TimeoutExpired

HARNESS_GEN_SCRIPT = "harness_generator.php"
DYNAMIC_CHECKER = "DynamicTaintChecker.php"

S2E_BOOTSTRAP_TEMPLATE_PATH = "bootstrap_template.sh"
PHP_PATH = "../php-src/sapi/cli/php"

S2E_TEMPLATE_DIR = "s2e_template"
S2E_PROJECTS_DIR = "projects"
HARNESS_DIR = ".harness"
OUTPUT_DIR = "SymWP"

TIMEOUT_MINUTES = 10
ARGV_LENGTH = 10
CORE = 16

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
        f.write('\nadd_plugin("FunctionMonitor")\nadd_plugin("EchoFunctionTracker")\npluginsConfig.EchoFunctionTracker = {\n    addressToTrack = 0xa9839b,\n}')

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
        with open(project_path + '/stdout.txt', 'w') as f:
            subprocess.run(
                ["s2e", "run", '-n', '-t', str(TIMEOUT_MINUTES), '-c', str(CORE), project_name],
                stdout=f, 
                stderr=subprocess.DEVNULL,
                # S2E's timeout option will not work sometime, make sure it will finish in time
                timeout=TIMEOUT_MINUTES * 60,
            )
    except TimeoutExpired:
        pass
        

def extract_symbolic_args(project_path):
    print("[+] Analyzing S2E output...")
    args = set()
    for log_file in Path(project_path).rglob("stdout.txt"):
        with open(log_file, "r") as f:
            for line in f:
                match = re.search(r'exploitable_args\[0x[\da-f]+\] = v\d+_arg(\d+)_\d+', line) # exploitable_args[0x7fa37c244838] = v0_arg2_0
                if match:
                    args.add(int(match.group(1)))
    return sorted(args)

# TODO: use symbolic_args after implementation of DYNAMIC_CHECKER
def run_dynamic_checker(harness_path, symbolic_args):
    print(f"[+] Running dynamic analysis on {Path(harness_path).name} with symbolic args: {symbolic_args}")
    result = subprocess.run(["php", DYNAMIC_CHECKER, harness_path], capture_output=True, text=True, check=True)
    return result.stdout

def main():
    if len(sys.argv) != 2:
        print(f"Usage: python {sys.argv[0]} <plugin_folder>")
        sys.exit(1)

    plugin_folder = sys.argv[1]
    plugin_name = Path(plugin_folder).name
    harness_dir = Path(plugin_folder) / HARNESS_DIR

    generate_harnesses(plugin_folder)

    if not harness_dir.exists():
        print(f"[-] Harness folder not found: {harness_dir}")
        sys.exit(1)

    harnesses = list((harness_dir).rglob('*.php'))
    for harness in harnesses:
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
            f.write('\n'.join(str(arg) for arg in symbolic_args))
        with open(f"{OUTPUT_DIR}/{Path(harness_path).name}.dynamic", 'w') as f:
            f.write(result)

if __name__ == "__main__":
    main()
