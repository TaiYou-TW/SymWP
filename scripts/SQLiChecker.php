<?php

class SQLiChecker
{
    private const PAYLOAD = "'\";/\\-+=*\`|)(#-- ,!@~<>%";
    private string $harnessPath;
    private array $argvValues;

    public function __construct(string $harnessPath, array $argv)
    {
        $this->harnessPath = $harnessPath;
        $this->argvValues = $argv;
    }

    public function run()
    {
        $results = [];

        for ($i = 0; $i < count($this->argvValues); $i++) {
            $originalArgv = $this->argvValues[$i];
            $this->argvValues[$i] = self::PAYLOAD;

            $output = $this->run_harness();
            if (is_null($output)) {
                $this->argvValues[$i] = $originalArgv;
                continue;
            }

            file_put_contents('.SQLiChecker_output.html', $output);
            echo "== Output Analysis for: $this->harnessPath ==\n";

            $results = array_merge($results, $this->detect_taint_exposure($output));

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
                $results[] = "🚨 Potential SQL injection detected: {$result[1]}\n";
            }
        }

        return $results;
    }
}

if ($argv && $argv[0] && realpath($argv[0]) === __FILE__) {
    if (count($argv) < 3) {
        die("Usage: php {$argv[0]} <harness_file> ...<argv>\n");
    }
    $checker = new SQLiChecker($argv[1], array_slice($argv, 2));
    $issues = $checker->run();

    if (empty($issues)) {
        echo "✅ No issues detected.\n";
    } else {
        foreach ($issues as $issue) {
            echo "{$issue}\n";
        }
    }
}