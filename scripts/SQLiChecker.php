<?php

class SQLiChecker
{
    private const PAYLOAD = "'\";/\\-+=*\`|)(#-- ,!@~<>%";
    private const OUTPUT_DIR = '.SQLiChecker_output';
    private string $harnessPath;
    private array $argvValues;

    public function __construct(string $harnessPath, array $argv)
    {
        $this->harnessPath = $harnessPath;
        $this->argvValues = $argv;
    }

    public function run(): array
    {
        $this->check_or_create_output_dir();

        $results = [];

        for ($i = 0; $i < count($this->argvValues); $i++) {
            $originalArgv = $this->argvValues[$i];
            $this->argvValues[$i] = self::PAYLOAD;

            $output = "Argv: ";
            for ($j = 0; $j < count($this->argvValues); $j++) {
                $output .= "arg{$j}=" . escapeshellarg($this->argvValues[$j]) . " ";
            }
            $output .= "\n\nOutput:\n";

            $result = $this->run_harness();
            if (is_null($result)) {
                $this->argvValues[$i] = $originalArgv;
                continue;
            }
            $output .= $result;

            file_put_contents(self::OUTPUT_DIR . '/' . basename($this->harnessPath) . ".arg{$i}.out", $output);
            echo "[*] Testing argv $i...\n";

            $results = array_merge($results, $this->detect_taint_exposure($result));

            $this->argvValues[$i] = $originalArgv;
        }

        return $results;
    }

    private function run_harness(): string|bool|null
    {
        $cmd = "php $this->harnessPath " . implode(' ', array_map('escapeshellarg', $this->argvValues)) . " 2>&1";
        return shell_exec($cmd);
    }

    private function detect_taint_exposure(string $output): array
    {
        $results = [];

        if (strpos($output, "WordPress database error") !== false) {
            $pattern = '/\[query\] => (.*)/i';
            preg_match($pattern, $output, $result);
            if (is_array($result) && count($result) > 1) {
                $results[] = "[!] Potential SQL injection detected: {$result[1]}";
            }
        }

        return $results;
    }

    private function check_or_create_output_dir()
    {
        if (!is_dir(self::OUTPUT_DIR)) {
            if (!mkdir(self::OUTPUT_DIR, 0755, true)) {
                echo "[!] Failed to create output directory: " . self::OUTPUT_DIR . "\n";
                exit(1);
            }
        }
    }
}

if ($argv && $argv[0] && realpath($argv[0]) === __FILE__) {
    if (count($argv) < 3) {
        die("Usage: php {$argv[0]} <harness_file> ...<argv>\n");
    }
    if (!file_exists($argv[1])) {
        die("[!] Harness file does not exist.\n");
    }

    $checker = new SQLiChecker($argv[1], array_slice($argv, 2));
    $issues = $checker->run();

    if (empty($issues)) {
        echo "[*] No issues detected.\n";
    } else {
        foreach ($issues as $issue) {
            echo "{$issue}\n";
        }
    }
}