<?php

class XSSChecker
{
    private const SPECIAL_SYMBOLS = [
        '\'',
        '"',
    ];
    private const TAINT_START_MARKER = '__TAINTED_VAR_S__';
    private const TAINT_END_MARKER = '__TAINTED_VAR_E__';
    private const XSS_PAYLOAD_MARKER = 'XSS_PAYLOAD_MARKER';
    private const PAYLOAD = self::TAINT_START_MARKER . "'\"<>/;=#`\\<script>alert(1)</script><img src=x onerror=alert(1)>" . self::TAINT_END_MARKER;
    private const OUTPUT_DIR = '.XSSChecker_output';

    private string $harnessPath;
    private array $argvValues;

    public function __construct(string $harnessPath, array $argv)
    {
        $this->harnessPath = $harnessPath;
        $this->argvValues = $argv;

        $found = false;
        for ($i = 0; $i < count($this->argvValues); $i++) {
            if ($this->argvValues[$i] === self::XSS_PAYLOAD_MARKER) {
                $found = true;
                $this->argvValues[$i] = self::PAYLOAD;
            }
        }

        if (!$found) {
         for ($i = 0; $i < count($this->argvValues); $i++) {
                $this->argvValues[$i] = self::PAYLOAD;
        }   
        }
    }

    public function run(): array
    {
        $this->check_or_create_output_dir();

        $results = [];

        for ($i = 0; $i < count($this->argvValues); $i++) {
            $output = "Argv: ";
            for ($j = 0; $j < count($this->argvValues); $j++) {
                $output .= "arg{$j}=" . escapeshellarg($this->argvValues[$j]) . " ";
            }
            $output .= "\n\nOutput:\n";

            $output .= $this->run_harness();
            if (is_null($output)) {
                continue;
            }

            file_put_contents(self::OUTPUT_DIR . '/' . basename($this->harnessPath) . ".arg{$i}.out", $output);
            echo "[*] Testing argv $i...\n";

            $results = array_merge($results, $this->detect_taint_exposure($output));
        }

        return $results;
    }

    private function run_harness(): string|bool|null
    {
        $cmd = "php $this->harnessPath " . implode(' ', array_map('escapeshellarg', $this->argvValues));
        return shell_exec($cmd);
    }

    private function detect_taint_exposure(string $output): array
    {
        $results = [];

        if (strpos($output, self::TAINT_START_MARKER) === false && strpos($output, self::TAINT_END_MARKER) === false) {
            return $results;
        }

        $results[] = "[+] Taint marker found in output";

        // Check injection in tag
        $tag_pattern = '/<([^>]+' . self::TAINT_START_MARKER . '.*' . self::TAINT_END_MARKER . '[^>]*)>/i';
        preg_match_all($tag_pattern, $output, $result);
        if (is_array($result)) {
            foreach ($result[1] as $index => $tag) {
                foreach (self::SPECIAL_SYMBOLS as $symbol) {
                    $count = substr_count($tag, $symbol);
                    if ($count % 2 == 1 && $count > 2) {
                        $results[] = "[!] Potential quotes breaks in tags detected: {$result[0][$index]}";
                    } elseif ($count === 0 && preg_match('/\w+=' . self::TAINT_START_MARKER . '.*\s+.*' . self::TAINT_END_MARKER . '/', $tag)) {
                        $results[] = "[!] Potential space breaks in tag without quotes detected: {$result[0][$index]}";
                    }
                }
            }
        }

        // Check injection between tags
        // Find if there are any tags with tainted marker can be inserted after tags
        // RegEx: <tag>, start_marker, <tag>, end_marker
        $tag_pattern = '/<[^>]+>[^<]*' . self::TAINT_START_MARKER . '.*(<[^>]+>).*' . self::TAINT_END_MARKER . '/i';
        preg_match_all($tag_pattern, $output, $result);
        if (is_array($result)) {
            foreach ($result[1] as $tag) {
                $results[] = "[!] Potential tags injection detected: {$tag}";
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
        die("Usage: php {$argv[0]} <harness_file> ...<argvs>\n");
    }
    if (!file_exists($argv[1])) {
        die("[!] Harness file does not exist.\n");
    }

    $checker = new XSSChecker($argv[1], array_slice($argv, 2));
    $issues = $checker->run();

    if (empty($issues)) {
        echo "[*] No issues detected.\n";
    } else {
        foreach ($issues as $issue) {
            echo "{$issue}\n";
        }
    }
}