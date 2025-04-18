<?php

const OUTPUT_FOLDER = '.harness';
const AVOID_FOLDERS = ['vendor', 'tests'];

function extract_php_files(string $dir): array
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = [];

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), needle: $dir . '/' . OUTPUT_FOLDER) || in_array($file->getFilename(), AVOID_FOLDERS))
            continue;
        $files[] = $file->getPathname();
    }

    return $files;
}

function get_plugin_entry_file(string $dir): string
{
    $di = new DirectoryIterator($dir);
    foreach ($di as $file) {
        if ($file->isFile() && is_contain_plugin_name(file_get_contents($file->getPathname()))) {
            return $file;
        }
    }
    return '';
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
                if (!in_array($match, $inputs[$patternKey] ?? [])) {
                    $inputs[$patternKey][] = $match;
                }
            }
        }
    }

    return $inputs;
}

function common_harness_header(): string
{
    global $plugin_entry_file;
    $output = <<<EOT
<?php
// This harness file is auto-generated. Do not edit.
require 'wordpress-loader.php';
require_once '$plugin_entry_file';\n
EOT;
    return $output;
}

function is_contain_plugin_name(string $code): string
{
    return preg_match('/Plugin Name:\s*(.+)/', $code, $_);
}

function get_output_path(string $outputDir, string $filename): string
{
    return $outputDir . DIRECTORY_SEPARATOR . $filename;
}

function get_dash_file_path(string $filepath): string
{
    $result = str_replace('/', '-', $filepath);
    $result = str_replace('.php', '-php', $result);
    return $result;
}

function remove_all_php_files_from_folder(string $outputDir): void
{
    if (!is_dir($outputDir)) {
        return;
    }
    array_map('unlink', glob("$outputDir/*.php"));
}

function append_user_input_vars_to_harness(string &$harness, array $inputs, int $startIndex = 1): int
{
    $argIndex = $startIndex;
    foreach ($inputs as $super => $vars) {
        foreach ($vars as $var) {
            $key = var_export($var, true);
            $harness .= "{$super}[$key] = \$argv[{$argIndex}];\n";
            $argIndex++;
        }
    }
    return $argIndex;
}

function append_function_params_to_harness(string &$harness, array $function, int $startIndex = 1): string
{
    $argIndex = $startIndex;
    $argsList = [];
    foreach ($function as $_) {
        $argsList[] = "\$argv[{$argIndex}]";
        $argIndex++;
    }

    return implode(', ', $argsList);
}

function generate_function_harness(string $filepath, array $function, array $inputs, string $outputDir): void
{
    $basename = get_dash_file_path($filepath);
    $funcname = $function['name'];
    $harnessName = "{$basename}_{$funcname}_func_harness.php";
    $outputPath = get_output_path($outputDir, $harnessName);

    $harness = common_harness_header();
    $harness .= "require_once '$filepath';\n";

    $argIndex = append_user_input_vars_to_harness($harness, $inputs);
    $args = append_function_params_to_harness($harness, $function['params'], $argIndex);
    $harness .= "{$funcname}({$args});\n";

    file_put_contents($outputPath, $harness);
    echo "Generated function harness: $outputPath\n";
}

function generate_method_harness(string $filepath, array $method, array $inputs, string $outputDir): void
{
    $basename = get_dash_file_path($filepath);
    $harnessName = "{$basename}_{$method['class']}_{$method['method']}_method_harness.php";
    $outputPath = get_output_path($outputDir, $harnessName);

    $harness = common_harness_header();
    $harness .= "require_once '$filepath';\n";

    $argIndex = append_user_input_vars_to_harness($harness, $inputs);
    $args = append_function_params_to_harness($harness, $method['params'], $argIndex);

    if ($method['is_static']) {
        if ($method['visibility'] === T_PRIVATE || $method['visibility'] === T_PROTECTED) {
            $harness .= "\$method = new ReflectionMethod(\"{$method['class']}\", \"{$method['method']}\");\n";
            $harness .= "\$method->setAccessible(true);\n";
            $harness .= "\$method->invoke(null" . ($args ? ", $args" : "") . ");\n";
        } else {
            $harness .= "{$method['class']}::{$method['method']}(" . ($args ?: "") . ");\n";
        }
    } else {
        $harness .= "\$obj = new {$method['class']}();\n";

        if ($method['visibility'] === T_PRIVATE || $method['visibility'] === T_PROTECTED) {
            $harness .= "\$method = new ReflectionMethod(\"{$method['class']}\", \"{$method['method']}\");\n";
            $harness .= "\$method->setAccessible(true);\n";
            $harness .= "\$method->invoke(\$obj" . ($args ? ", $args" : "") . ");\n";
        } else {
            $harness .= "\$obj->{$method['method']}(" . ($args ?: "") . ");\n";
        }
    }

    file_put_contents($outputPath, $harness);
    echo "Generated method harness: $outputPath\n";
}

function is_inline_php_file(string $code): bool
{
    $tokens = token_get_all($code);
    $depth = 0;

    foreach ($tokens as $i => $token) {
        if (is_array($token)) {
            if ($token[0] === T_FUNCTION || $token[0] === T_CLASS) {
                $depth++;
            }

            // global executions
            if ($depth === 0 && in_array($token[0], [T_ECHO, T_PRINT, T_INLINE_HTML, T_VARIABLE, T_REQUIRE, T_INCLUDE])) {
                return true;
            }

            // function calls in global scope
            if ($depth === 0 && $token[0] === T_STRING && isset($tokens[$i + 1]) && $tokens[$i + 1] === '(') {
                return true;
            }
        } else {
            if ($token === '{')
                $depth++;
            if ($token === '}')
                $depth = max(0, $depth - 1);
        }
    }

    return false;
}

function generate_inline_harness(string $filepath, array $inputs, string $outputDir): void
{
    $basename = get_dash_file_path($filepath);
    $harnessName = "{$basename}_inline_harness.php";
    $outputPath = get_output_path($outputDir, $harnessName);

    $harness = common_harness_header();
    append_user_input_vars_to_harness($harness, $inputs);

    $harness .= "require_once '$filepath';\n";

    file_put_contents($outputPath, $harness);
    echo "Generated inline harness: $outputPath\n";
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
    remove_all_php_files_from_folder($outputDir);
}

$phpFiles = extract_php_files($targetDir);
$plugin_entry_file = get_plugin_entry_file($targetDir);
if ($plugin_entry_file === '') {
    echo "No plugin entry file found. Please ensure the plugin header is present in one of the files.\n";
    exit(1);
}

foreach ($phpFiles as $phpFile) {
    $code = file_get_contents($phpFile);

    [$functions, $methods] = extract_functions_and_methods($code);


    if (is_inline_php_file($code)) {
        $inputs = extract_user_input_vars($code);
        if (!empty($inputs)) {
            generate_inline_harness($phpFile, $inputs, $outputDir);
        }
    } else {
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
                    $method,
                    $inputs,
                    $outputDir,
                );
            }
        }
    }
}
