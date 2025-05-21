<?php

class XSSChecker
{
    private const ARGV_COUNT = 10; // Number of arguments to pass to the harness
    private const SPECIAL_SYMBOLS = [
        '\'',
        '"',
    ];
    private const TAINT_START_MARKER = '__TAINTED_VAR_S__';
    private const TAINT_END_MARKER = '__TAINTED_VAR_E__';

    private string $harnessPath;
    private array $argvValues;

    public function __construct($harnessPath)
    {
        $this->harnessPath = $harnessPath;

        $taintedPayload = self::TAINT_START_MARKER . "'\"<>/;=#`\\<h1>a</h1><script>alert(1)</script><img src=x onerror=alert(1)>" . self::TAINT_END_MARKER;
        $this->argvValues = array_fill(0, self::ARGV_COUNT, $taintedPayload);
    }

    public function run()
    {
        $output = $this->run_harness();

        file_put_contents('.XSSChecker_output.html', $output);
        echo "== Output Analysis for: $this->harnessPath ==\n";

        return $this->detect_taint_exposure($output);
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

        $results[] = "⚠️ Taint marker found in output";

        // Check injection in tag
        $tag_pattern = '/<([^\/>]+' . self::TAINT_START_MARKER . '.*' . self::TAINT_END_MARKER . '[^>]*)>/i';
        preg_match_all($tag_pattern, $output, $result);
        if (is_array($result)) {
            foreach ($result[1] as $index => $tag) {
                foreach (self::SPECIAL_SYMBOLS as $symbol) {
                    $count = substr_count($tag, $symbol);
                    if ($count % 2 == 1 && $count > 2) {
                        $results[] = "🚨 Potential quotes breaks in tags detected: {$result[0][$index]}\n";
                    } elseif ($count === 0 && preg_match('/\w+=' . self::TAINT_START_MARKER . '.*\s+.*' . self::TAINT_END_MARKER . '/', $tag)) {
                        $results[] = "🚨 Potential space breaks in tag without quotes detected: {$result[0][$index]}\n";
                    }
                }
            }
        }

        // Check injection between tags
        // Find if there are any tags with tainted marker can be inserted after tags
        $tag_pattern = '/(?:(?:(?:(?:<[^\/>]+>[^<]*<\/[^>]+>)|(?:<[^\/>]+\/?>))\\s*.*))' . self::TAINT_START_MARKER . '.*((<[^\/>]+>[^<]*<\/[^>]+>)|(<[^\/>]+\/\\s*>)).*' . self::TAINT_END_MARKER . '/i';
        preg_match_all($tag_pattern, $output, $result);
        if (is_array($result)) {
            foreach ($result[1] as $tag) {
                $results[] = "🚨 Potential tags injection detected: {$tag}\n";
            }
        }

        return $results;
    }
}

if ($argv && $argv[0] && realpath($argv[0]) === __FILE__) {
    if (count($argv) < 2) {
        die("Usage: php {$argv[0]} <harness_file>\n");
    }
    $checker = new XSSChecker($argv[1]);
    $issues = $checker->run();

    if (empty($issues)) {
        echo "✅ No issues detected.\n";
    } else {
        foreach ($issues as $issue) {
            echo "{$issue}\n";
        }
    }
}