<?php

const ARGV_COUNT = 5; // Number of arguments to pass to the harness

function run_harness($harnessPath, $taintedInput)
{
    $cmd = "php $harnessPath " . implode(' ', array_map('escapeshellarg', $taintedInput));
    return shell_exec($cmd);
}

function detect_taint_exposure($output, $taintMarker)
{
    $special_symbols = [
        '\'',
        '"',
    ];
    $results = [];

    if (strpos($output, $taintMarker) !== false) {
        $results[] = "⚠️ Taint marker found in output";

        // check injection in tag
        $tag_pattern = '/<([^>]+' . preg_quote($taintMarker, '/') . '[^>]*)>/i';
        preg_match_all($tag_pattern, $output, $result);
        if (is_array($result)) {
            foreach ($result[1] as $tag) {
                foreach ($special_symbols as $symbol) {
                    $count = substr_count($tag, $symbol);
                    if ($count % 2 == 1 && $count > 2) { // can use quotes in tag
                        $results[] = "🚨 Potential grammar-breaking injection detected: {$tag}";
                    } else if ($count === 0) { // can use space and no qoutes in tag
                        $results[] = "🚨 Potential grammar-breaking injection detected: {$tag}";
                    }
                }
            }
        }

        // TODO: check injection between tags

    }

    return $results;
}

// === CONFIGURATION ===
$harnessPath = $argv[1] ?? die("Usage: php {$argv[0]} <harness_file>\n");
$taintMarker = '__TAINTED_VAR__';
$taintedPayload = "'\"<>/;=#`\\{$taintMarker}<h1>a</h1><script>alert(1)</script><img src=x onerror=alert(1)>";

$argvValues = array_fill(0, ARGV_COUNT, $taintedPayload);

// === RUN & ANALYZE ===
$output = run_harness($harnessPath, $argvValues);

file_put_contents('.dynamic_output.html', $output); // optional debug output

echo "== Output Analysis for: $harnessPath ==\n";
$issues = detect_taint_exposure($output, $taintMarker);
if (empty($issues)) {
    echo "✅ No issues detected.\n";
} else {
    foreach ($issues as $issue) {
        echo "{$issue}\n";
    }
}
