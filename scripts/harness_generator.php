<?php

function extract_php_files($dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = [];

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') continue;
        $files[] = $file->getPathname();
    }

    return $files;
}

function extract_functions_and_methods($content) {
    $tokens = token_get_all($content);
    $functions = [];
    $classes = [];
    $count = count($tokens);

    $inClass = false;
    $className = '';
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
            $inClass = true;
            $className = $tokens[$i + 2][1];
            continue;
        }

        if ($tokens[$i][0] === T_FUNCTION) {
            $name = '';
            $params = [];
            $body = '';
            $braceCount = 0;
            $start = false;

            // Get function name
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    break;
                }
            }

            // Get function params
            $paramIndex = $j + 1;
            if ($tokens[$paramIndex] === '(') {
                $paramIndex++;
                while ($tokens[$paramIndex] !== ')') {
                    if (is_array($tokens[$paramIndex]) && $tokens[$paramIndex][0] === T_VARIABLE) {
                        $params[] = substr($tokens[$paramIndex][1], 1); // remove $
                    }
                    $paramIndex++;
                    if ($paramIndex >= $count) break;
                }
            }

            // Extract body
            for (; $i < $count; $i++) {
                if ($tokens[$i] === '{') {
                    $start = true;
                    $braceCount++;
                } elseif ($tokens[$i] === '}') {
                    $braceCount--;
                }

                if ($start) {
                    $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                    if ($braceCount === 0) break;
                }
            }

            if ($name && $body) {
                if ($inClass) {
                    $classes[] = ['class' => $className, 'method' => $name, 'params' => $params, 'body' => $body];
                } else {
                    $functions[] = ['name' => $name, 'params' => $params, 'body' => $body];
                }
            }
            continue;
        }

        if ($tokens[$i] === '}') {
            $inClass = false;
            $className = '';
            continue;
        }
    }

    return [$functions, $classes];
}

function extract_user_input_vars($body) {
    $inputs = [];
    $patterns = ['\$_GET', '\$_POST', '\$_REQUEST', '\$_COOKIE', '\$_FILES'];

    foreach ($patterns as $pattern) {
        if (preg_match_all("/{$pattern}\s*\[\s*['\"]([^'\"]+)['\"]\s*\]/", $body, $matches)) {
            foreach ($matches[1] as $match) {
                $patternKey = str_replace('\\', '', $pattern); // remove \ from patterns
                $inputs[$patternKey][] = $match;
            }
        }
    }

    return $inputs;
}

function common_harness_header() {
    $output = <<<EOT
<?php
// This file is auto-generated. Do not edit.
require './wordpress-loader.php';

EOT;
    return $output;
}

function generate_function_harness($filepath, $function, $inputs, $outputDir) {
    $basename = basename($filepath, '.php');
    $funcname = $function['name'];
    $harnessName = "{$basename}_{$funcname}_func_harness.php";
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $harnessName;

    $harness = common_harness_header();

    $argIndex = 1;
    foreach ($inputs as $super => $vars) {
        foreach ($vars as $var) {
            echo $var."\n";
            $key = var_export($var, true);
            echo $key."\n";
            $harness .= "{$super}[$key] = \$argv[{$argIndex}];\n";
            $argIndex++;
        }
    }

    $argsList = [];
    foreach ($function['params'] as $param) {
        $argsList[] = "\$argv[{$argIndex}]";
        $argIndex++;
    }

    $args = implode(', ', $argsList);
    $harness .= "{$funcname}({$args});\n";

    file_put_contents($outputPath, $harness);
    echo "Generated function harness: $outputPath\n";
}

function generate_method_harness($filepath, $class, $method, $params, $inputs, $outputDir) {
    $basename = basename($filepath, '.php');
    $harnessName = "{$basename}_{$class}_{$method}_method_harness.php";
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $harnessName;

    $harness = common_harness_header();

    $argIndex = 1;
    foreach ($inputs as $super => $vars) {
        foreach ($vars as $var) {
            $key = var_export($var, true);
            $harness .= "{$super}[$key] = \$argv[{$argIndex}];\n";
            $argIndex++;
        }
    }

    $argsList = [];
    foreach ($params as $_) {
        $argsList[] = "\$argv[{$argIndex}]";
        $argIndex++;
    }

    $args = implode(', ', $argsList);
    $harness .= "\$obj = new {$class}();\n";
    $harness .= "\$obj->{$method}({$args});\n";

    file_put_contents($outputPath, $harness);
    echo "Generated method harness: $outputPath\n";
}


if ($argc < 2) {
    echo "Usage: php $argv[0] <target_directory>\n";
    exit(1);
}
$targetDir = $argv[1] ?? '.';
$outputDir = "$targetDir/.harness";

if (!is_dir($outputDir)) {
    if (!mkdir($outputDir)) {
        echo "Failed to create output directory: $outputDir\n";
        exit(1);
    }
}

$phpFiles = extract_php_files($targetDir);

foreach ($phpFiles as $phpFile) {
    $code = file_get_contents($phpFile);
    [$functions, $methods] = extract_functions_and_methods($code);

    foreach ($functions as $function) {
        $inputs = extract_user_input_vars($function['body']);
        if (!empty($inputs) || !empty($function['params'])) {
            generate_function_harness($phpFile, $function, $inputs, $outputDir);
        }
    }

    foreach ($methods as $method) {
        $inputs = extract_user_input_vars($method['body']);
        if (!empty($inputs) || !empty($method['params'])) {
            generate_method_harness($phpFile, $method['class'], $method['method'], $method['params'], $inputs, $outputDir);
        }
    }
}
