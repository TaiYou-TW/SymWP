<?php

const ARGV_COUNT = 5; // Number of arguments to pass to the harness
const SPECIAL_SYMBOLS = [
    '\'',
    '"',
];
const TAINT_MARKER = '__TAINTED_VAR__';

function run_harness(string $harnessPath, array $taintedInput): bool|string|null
{
    $cmd = "php $harnessPath " . implode(' ', array_map('escapeshellarg', $taintedInput));
    return shell_exec($cmd);
}

function detect_taint_exposure(string $output): array
{
    $results = [];

    if (strpos($output, TAINT_MARKER) === false) {
        return $results;
    }

    $results[] = "⚠️ Taint marker found in output";

    // check injection in tag
    $tag_pattern = '/<([^>]+' . preg_quote(TAINT_MARKER, '/') . '[^>]*)>/i';
    preg_match_all($tag_pattern, $output, $result);
    if (is_array($result)) {
        foreach ($result[1] as $tag) {
            foreach (SPECIAL_SYMBOLS as $symbol) {
                $count = substr_count($tag, $symbol);
                if (
                    ($count % 2 == 1 && $count > 2) || // odd number of quotes
                    $count === 0 // no quotes
                ) {
                    $results[] = "🚨 Potential grammar-breaking injection detected: {$tag}";
                }
            }
        }
    }

    // TODO: check injection between tags

    return $results;
}

// === CONFIGURATION ===
$harnessPath = $argv[1] ?? die("Usage: php {$argv[0]} <harness_file>\n");
$taintedPayload = "'\"<>/;=#`\\" . TAINT_MARKER . "<h1>a</h1><script>alert(1)</script><img src=x onerror=alert(1)>";
$argvValues = array_fill(0, ARGV_COUNT, $taintedPayload);

// === RUN & ANALYZE ===
$output = run_harness($harnessPath, $argvValues);

file_put_contents('.dynamic_output.html', $output); // optional debug output

echo "== Output Analysis for: $harnessPath ==\n";
$issues = detect_taint_exposure($output);
if (empty($issues)) {
    echo "✅ No issues detected.\n";
} else {
    foreach ($issues as $issue) {
        echo "{$issue}\n";
    }
}
