<?php

const OUTPUT_FOLDER = '.harness';

function extract_php_files(string $dir): array
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = [];

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $dir . '/' . OUTPUT_FOLDER))
            continue;
        $files[] = $file->getPathname();
    }

    return $files;
}

function extract_functions_and_methods(string $content): array
{
    $tokens = token_get_all($content);
    $functions = [];
    $classes = [];
    $count = count($tokens);

    $inClass = false;
    $className = '';
    $visibility = T_PUBLIC;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
            $inClass = true;
            $className = $tokens[$i + 2][1];
            continue;
        }

        if ($inClass && in_array($tokens[$i][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
            $visibility = $tokens[$i][0];
            continue;
        }

        // function or method
        if ($tokens[$i][0] === T_FUNCTION) {
            $name = '';
            $params = [];
            $body = '';
            $braceCount = 0;
            $start = false;
            $is_static = $tokens[$i - 2][0] === T_STATIC;

            // Get function name
            for ($i++; $i < $count; $i++) {
                if ($tokens[$i][0] === T_STRING) {
                    $name = $tokens[$i][1];
                    break;
                }
            }

            // Get function parameters
            for ($i++; $i < $count; $i++) {
                if ($tokens[$i] === '(') {
                    $start = true;
                }
                if ($tokens[$i] === ')') {
                    $start = false;
                    break;
                }
                if ($start && is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                    $params[] = substr($tokens[$i][1], 1);
                }
            }

            // Extract function body
            for ($i++; $i < $count; $i++) {
                if ($tokens[$i] === '{') {
                    $start = true;
                    $braceCount++;
                } elseif ($tokens[$i] === '}') {
                    $braceCount--;
                }

                if ($start) {
                    $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                    if ($braceCount === 0) {
                        $start = false;
                        break;
                    }
                }
            }

            if ($name && $body) {
                if ($inClass) {
                    $classes[] = [
                        'class' => $className,
                        'method' => $name,
                        'params' => $params,
                        'body' => $body,
                        'visibility' => $visibility,
                        'is_static' => $is_static,
                    ];
                } else {
                    $functions[] = ['name' => $name, 'params' => $params, 'body' => $body];
                }
            }

            $visibility = T_PUBLIC; // reset for next method
            continue;
        }

        if ($tokens[$i] === '}') {
            $inClass = false;
            $className = '';
            $visibility = T_PUBLIC;
            continue;
        }
    }

    return [$functions, $classes];
}

function extract_user_input_vars(string $body): array
{
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

function common_harness_header(string $filepath): string
{
    global $plugin_entry_file;
    assert($plugin_entry_file !== '', 'Plugin entry file not set');
    $output = <<<EOT
<?php
// This harness file is auto-generated. Do not edit.
require 'wordpress-loader.php';
require_once '$plugin_entry_file';
require_once '$filepath';\n
EOT;
    return $output;
}

function generate_function_harness(string $filepath, array $function, array $inputs, string $outputDir): void
{
    $basename = basename($filepath, '.php');
    $funcname = $function['name'];
    $harnessName = "{$basename}_{$funcname}_func_harness.php";
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $harnessName;

    $harness = common_harness_header($filepath);

    $argIndex = 1;
    foreach ($inputs as $super => $vars) {
        foreach ($vars as $var) {
            $key = var_export($var, true);
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

function generate_method_harness(string $filepath, string $class, string $method, array $params, array $inputs, string $outputDir, int $visibility, bool $is_static): void
{
    $basename = basename($filepath, '.php');
    $harnessName = "{$basename}_{$class}_{$method}_method_harness.php";
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $harnessName;

    $harness = common_harness_header($filepath);

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

    if ($is_static) {
        if ($visibility === T_PRIVATE || $visibility === T_PROTECTED) {
            $harness .= "\$method = new ReflectionMethod(\"{$class}\", \"{$method}\");\n";
            $harness .= "\$method->setAccessible(true);\n";
            $harness .= "\$method->invoke(null" . ($args ? ", $args" : "") . ");\n";
        } else {
            $harness .= "{$class}::{$method}(" . ($args ?: "") . ");\n";
        }
    } else {
        $harness .= "\$obj = new {$class}();\n";

        if ($visibility === T_PRIVATE || $visibility === T_PROTECTED) {
            $harness .= "\$method = new ReflectionMethod(\"{$class}\", \"{$method}\");\n";
            $harness .= "\$method->setAccessible(true);\n";
            $harness .= "\$method->invoke(\$obj" . ($args ? ", $args" : "") . ");\n";
        } else {
            $harness .= "\$obj->{$method}(" . ($args ?: "") . ");\n";
        }
    }

    file_put_contents($outputPath, $harness);
    echo "Generated method harness: $outputPath\n";
}

if ($argc < 2) {
    echo "Usage: php {$argv[0]} <target_directory>\n";
    exit(1);
}
$targetDir = $argv[1];
$outputDir = "$targetDir/" . OUTPUT_FOLDER;

if (!is_dir($outputDir)) {
    if (!mkdir($outputDir)) {
        echo "Failed to create output directory: $outputDir\n";
        exit(1);
    }
} else {
    array_map('unlink', glob("$outputDir/*.php"));
}

$phpFiles = extract_php_files($targetDir);
$plugin_entry_file = '';

foreach ($phpFiles as $phpFile) {
    $code = file_get_contents($phpFile);
    if ($plugin_entry_file === '' && preg_match('/Plugin Name:\s*(.+)/', $code, $matches)) {
        $plugin_entry_file = $phpFile;
    } else if ($plugin_entry_file === '') {
        echo "No plugin entry file found. Please ensure the plugin header is present in one of the files.\n";
        exit(1);
    }

    [$functions, $methods] = extract_functions_and_methods($code);

    foreach ($functions as $function) {
        $inputs = extract_user_input_vars($function['body']);
        if (!empty($inputs)) {
            generate_function_harness($phpFile, $function, $inputs, $outputDir);
        }
    }

    foreach ($methods as $method) {
        $inputs = extract_user_input_vars($method['body']);
        if (!empty($inputs)) {
            generate_method_harness(
                $phpFile,
                $method['class'],
                $method['method'],
                $method['params'],
                $inputs,
                $outputDir,
                $method['visibility'],
                $method['is_static'],
            );
        }
    }
}
