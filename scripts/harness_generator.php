<?php

const OUTPUT_FOLDER = '.harness';
const AVOID_FOLDERS = ['vendor', 'tests'];

const WP_REST_REQUEST = 'WP_REST_Request';
const WP_REST_REQUEST_SET_PARAM = 'set_param';
const WP_REST_REQUEST_SET_QUERY_PARAMS = 'set_query_params';
const WP_REST_REQUEST_SET_BODY_PARAMS = 'set_body_params';
const WP_REST_REQUEST_SET_BODY = 'set_body';
const WP_REST_REQUEST_SET_PARAMS_METHODS = [
    WP_REST_REQUEST_SET_PARAM,
    WP_REST_REQUEST_SET_QUERY_PARAMS,
    WP_REST_REQUEST_SET_BODY_PARAMS,
    WP_REST_REQUEST_SET_BODY,
];
const SKIP_FUNCTION_CALLS = [
    'defined',
    'class_exists',
    'function_exists',
];
const PLUGIN_FOLDER_PREFIX = 'wp-content/plugins/';

enum HarnessType
{
    case concrete;
    case symbolic;
}

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
            return $file->getPathname();
        }
    }
    return '';
}

function extract_functions_and_methods(string $content): array
{
    $tokens = token_get_all($content);
    $functions = [];
    $methods = [];
    $count = count($tokens);

    $inClass = false;
    $inCurly = false;
    $inHeredoc = false;
    $namespace = '';
    $className = '';
    $visibility = T_PUBLIC;

    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i])) {
            $type = $tokens[$i][0];

            if (
                in_array($type, [
                    T_CURLY_OPEN,
                    T_DOLLAR_OPEN_CURLY_BRACES,
                    T_STRING_VARNAME,
                ])
            ) {
                $inCurly = true;
            }

            if ($type === T_START_HEREDOC) {
                $inHeredoc = true;
                continue;
            }

            if ($type === T_END_HEREDOC) {
                $inHeredoc = false;
                continue;
            }

            if ($inHeredoc) {
                continue;
            }
        }

        // namespace
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
            $i += 2;
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE])) {
                $namespace = "{$tokens[$i][1]}\\";
            }
            continue;
        }

        if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
            $inClass = true;
            $className = $namespace . $tokens[$i + 2][1];
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
            $isStatic = $tokens[$i - 2][0] === T_STATIC;

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
                    $type = null;
                    // Parameters may not have type hints, so we also check the previous tokens
                    if (($tokens[$i - 2][0] ?? null) === T_STRING && ($tokens[$i - 1][0] ?? null) === T_WHITESPACE) {
                        $type = $tokens[$i - 2];
                    }
                    $params[] = [
                        'type' => $type,
                        'name' => substr($tokens[$i][1], 1),
                        'default' => $tokens[$i + 2] ?? null,
                    ];
                }
            }

            // Extract function body
            for ($i++; $i < $count; $i++) {
                if (is_array($tokens[$i])) {
                    $type = $tokens[$i][0];

                    if (
                        in_array($type, [
                            T_CURLY_OPEN,
                            T_DOLLAR_OPEN_CURLY_BRACES,
                            T_STRING_VARNAME,
                        ])
                    ) {
                        $inCurly = true;
                    }

                    if ($type === T_START_HEREDOC) {
                        $inHeredoc = true;
                        continue;
                    }

                    if ($type === T_END_HEREDOC) {
                        $inHeredoc = false;
                        continue;
                    }

                    if ($inHeredoc) {
                        continue;
                    }
                }

                if ($tokens[$i] === '{') {
                    $start = true;
                    $braceCount++;
                } elseif ($tokens[$i] === '}') {
                    if ($inCurly) {
                        $inCurly = false;
                    } else {
                        $braceCount--;
                    }
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
                    $methods[] = [
                        'class' => $className,
                        'method' => $name,
                        'params' => $params,
                        'body' => $body,
                        'visibility' => $visibility,
                        'is_static' => $isStatic,
                    ];
                } else {
                    $functions[] = [
                        'name' => $name,
                        'params' => $params,
                        'body' => $body
                    ];
                }
            }

            $visibility = T_PUBLIC;
            continue;
        }

        if ($tokens[$i] === '}') {
            if ($inCurly) {
                $inCurly = false;
                continue;
            }
            $inClass = false;
            $className = '';
            $visibility = T_PUBLIC;
            continue;
        }
    }

    return [$functions, $methods];
}

function extract_user_input_vars(string $body): array
{
    $inputs = [];
    $patterns = ['\$_GET', '\$_POST', '\$_REQUEST', '\$_COOKIE', '\$_FILES', '\$_SERVER', '\$_ENV'];

    foreach ($patterns as $pattern) {
        if (preg_match_all("/{$pattern}\s*\[\s*['\"]([^'\"]+)['\"]\s*\]/", $body, $matches)) {
            foreach ($matches[1] as $match) {
                $patternKey = str_replace('\\', '', $pattern);

                // simple check for non user-defined $_SERVER variables
                if ($patternKey === '$_SERVER' && !str_starts_with($match, "HTTP_")) {
                    continue;
                }

                if (!in_array($match, $inputs[$patternKey] ?? [])) {
                    $inputs[$patternKey][] = $match;
                }
            }
        }
    }

    // Find user-input from filter_input()
    $filterInputIndexTable = [
        'INPUT_GET' => '$_GET',
        'INPUT_POST' => '$_POST',
        'INPUT_COOKIE' => '$_COOKIE',
        'INPUT_SERVER' => '$_SERVER',
        'INPUT_ENV' => '$_ENV',
    ];
    if (preg_match_all("/filter_input\s*\(\s*([^,\s]+)\s*,\s*['\"]([^'\"]+)['\"][^)]*\)/", $body, $matches)) {
        $index = $filterInputIndexTable[$matches[1][0]];
        $inputs[$index][] = $matches[2][0];
    }

    // TODO: those three patterns are not perfect and may not cover all cases.
    // They may be false positives or false negatives. Implement a more robust solution later.

    // ->get_param('key')
    if (preg_match_all("/->get_param\s*\(\s*['\"]([^'\"]+)['\"]\s*\)/", $body, $matches)) {
        foreach ($matches[1] as $match) {
            $inputs[WP_REST_REQUEST_SET_PARAM][] = $match;
        }
    }

    // ->get_query_params()['key']
    if (preg_match_all("/->get_query_params\s*\(\s*\)\s*\[\s*['\"]([^'\"]+)['\"]\s*\]/", $body, $matches)) {
        foreach ($matches[1] as $match) {
            $inputs[WP_REST_REQUEST_SET_PARAM][] = $match;
        }
    }

    // $request['key']
    // Be conservative: only include if variable is named `$request`
    if (preg_match_all("/\\\$request\s*\[\s*['\"]([^'\"]+)['\"]\s*\]/", $body, $matches)) {
        foreach ($matches[1] as $key => $value) {
            $inputs[WP_REST_REQUEST_SET_PARAM][] = $value;
        }
    }

    // ->get_json_params()['key']
    if (preg_match_all("/->get_json_params\s*\(\s*\)\s*\[\s*['\"]([^'\"]+)['\"]\s*\]/", $body, $matches)) {
        foreach ($matches[1] as $match) {
            $inputs[WP_REST_REQUEST_SET_BODY][] = $match;
        }
    }

    $inputs = array_merge_recursive($inputs, extract_user_input_vars_from_wp_rest_request($body));

    return $inputs;
}

function extract_user_input_vars_from_wp_rest_request(string $body): array
{
    $tokens = token_get_all("<?php\n" . $body);
    $paramVars = [];
    $count = count($tokens);
    $inputs = [];

    // Step 1: Find "$params = $request->get_query_params();"
    for ($i = 0; $i < $count - 6; $i++) {
        if (
            is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE &&
            $tokens[$i + 2] === '=' &&
            is_array($tokens[$i + 4]) && $tokens[$i + 4][0] === T_VARIABLE && $tokens[$i + 4][1] === '$request' &&
            is_array($tokens[$i + 5]) && $tokens[$i + 5][0] === T_OBJECT_OPERATOR &&
            is_array($tokens[$i + 6]) && $tokens[$i + 6][0] === T_STRING &&
            ($tokens[$i + 6][1] === 'get_query_params' || $tokens[$i + 6][1] === 'get_body_params' || $tokens[$i + 6][1] === 'get_json_params')
        ) {
            if ($tokens[$i + 6][1] === 'get_query_params') {
                $super = WP_REST_REQUEST_SET_QUERY_PARAMS;
            } else if ($tokens[$i + 6][1] === 'get_body_params') {
                $super = WP_REST_REQUEST_SET_BODY_PARAMS;
            } else if ($tokens[$i + 6][1] === 'get_json_params') {
                $super = WP_REST_REQUEST_SET_BODY;
            } else {
                echo "Error: Unknown method {$tokens[$i + 6][1]}.\n";
                continue;
            }
            $paramVars[] = [
                'var' => $tokens[$i][1],
                'method' => $super,
            ];
        }
    }

    // Step 2: Track accesses to those vars
    for ($i = 0; $i < $count - 3; $i++) {
        if (
            is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE &&
            in_array($tokens[$i][1], array_column($paramVars, 'var'))
        ) {
            // $params['page']
            if (
                $tokens[$i + 1] === '[' &&
                is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
            ) {
                $paramVar = array_filter($paramVars, fn($var) => $var['var'] === $tokens[$i][1]);
                if (empty($paramVar)) {
                    echo "Error: Unknown variable {$tokens[$i][1]}.\n";
                    continue;
                }
                $paramVar = array_values($paramVar)[0];
                $super = $paramVar['method'];
                $key = trim($tokens[$i + 2][1], "'\"");
                if (!isset($inputs[$super]) || !in_array($key, $inputs[$super])) {
                    $inputs[$super][] = $key;
                }
                continue;
            }
            // $params
            // TODO: How to generate suitable array for $params?
        }
    }

    return $inputs;
}

function common_harness_header(HarnessType $type): string
{
    global $plugin_entry_file;

    if ($type === HarnessType::symbolic) {
        $wordpress_loader = 'symbolic-wordpress-loader.php';
    } elseif ($type === HarnessType::concrete) {
        $wordpress_loader = 'concrete-wordpress-loader.php';
    } else {
        throw new InvalidArgumentException("Invalid harness type: " . $type->name);
    }

    $timestamp = date("Y-m-d H:i:s");
    $output = <<<EOT
<?php
// This harness file is auto-generated at $timestamp. Do not edit.
require '$wordpress_loader';
require_once '$plugin_entry_file';
do_action('plugins_loaded');

EOT;
    return $output;
}

function is_contain_plugin_name(string $code): string
{
    return preg_match('/Plugin Name:\s*(.+)/', $code, $_);
}

function get_output_path(string $outputDir, string $filename): string
{
    $filename = str_replace(['\\', '/', ' '], '-', $filename);
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

function get_wp_request_method(array $inputs): string
{
    foreach ($inputs as $super => $_) {
        if (in_array($super, WP_REST_REQUEST_SET_PARAMS_METHODS)) {
            if ($super === WP_REST_REQUEST_SET_BODY_PARAMS || $super === WP_REST_REQUEST_SET_BODY) {
                return 'POST';
            }
        }
    }
    return 'GET';
}

function append_user_input_vars_to_harness(string &$harness, array $inputs, string $filepath, int $startIndex = 1): int
{
    $argIndex = $startIndex;
    $wp_rest_request_init = false;
    foreach ($inputs as $super => $vars) {
        if (in_array($super, WP_REST_REQUEST_SET_PARAMS_METHODS)) {
            if (!$wp_rest_request_init) {
                $method = get_wp_request_method($inputs);
                $harness .= "\$request = new WP_REST_Request('{$method}', '" . PLUGIN_FOLDER_PREFIX . "{$filepath}');\n";
                $wp_rest_request_init = true;
            }
            if ($super === WP_REST_REQUEST_SET_PARAM) {
                foreach ($vars as $var) {
                    $key = var_export($var, true);
                    $harness .= "\$request->$super($key, \$argv[{$argIndex}]);\n";
                    $argIndex++;
                }
            } elseif ($super === WP_REST_REQUEST_SET_QUERY_PARAMS || $super === WP_REST_REQUEST_SET_BODY_PARAMS) {
                $harness .= "\$params = [];\n";
                foreach ($vars as $var) {
                    $key = var_export($var, true);
                    $harness .= "\$params[$key] = \$argv[{$argIndex}];\n";
                    $argIndex++;
                }
                $harness .= "\$request->$super(\$params);\n";
            } elseif ($super === WP_REST_REQUEST_SET_BODY) {
                $harness .= "\$request->set_header('Content-Type', 'application/json');\n";
                $args = '';
                foreach ($vars as $var) {
                    $key = var_export($var, true);
                    $args .= "    {$key} => \$argv[{$argIndex}],\n";
                    $argIndex++;
                }

                $harness .= "\$request->set_body(json_encode([\n$args]));\n";
            }
        } else {
            foreach ($vars as $var) {
                $key = var_export($var, true);
                $harness .= "{$super}[$key] = \$argv[{$argIndex}];\n";
                $argIndex++;
            }
        }
    }
    return $argIndex;
}

function append_function_params_to_harness(string &$harness, array $params, int $startIndex = 1): string
{
    $argIndex = $startIndex;
    $argsList = [];
    foreach ($params as $param) {
        // function parameter may have no type, so we also assume it's WP_REST_REQUEST by variable name
        if ($param['type'] === WP_REST_REQUEST || $param['name'] === 'request') {
            $argsList[] = "\$request";
        } else {
            $argsList[] = "\$argv[{$argIndex}]";
        }
        $argIndex++;
    }

    return implode(', ', $argsList);
}

function generate_function_harness(string $filepath, array $function, array $inputs, string $outputDir): void
{
    $basename = get_dash_file_path($filepath);
    $funcname = $function['name'];

    foreach (HarnessType::cases() as $type) {
        $harnessName = "{$basename}_{$funcname}_func_harness.php";
        $dir = $outputDir . DIRECTORY_SEPARATOR . $type->name;
        $outputPath = get_output_path($dir, $harnessName);

        $harness = common_harness_header($type);
        $harness .= "require_once '$filepath';\n";

        $argIndex = append_user_input_vars_to_harness($harness, $inputs, $filepath);
        $args = append_function_params_to_harness($harness, $function['params'], $argIndex);
        $harness .= "{$funcname}({$args});\n";

        file_put_contents($outputPath, $harness);
    }
}

function generate_method_harness(string $filepath, array $method, array $inputs, string $outputDir): void
{
    $basename = get_dash_file_path($filepath);

    foreach (HarnessType::cases() as $type) {
        $harnessName = "{$basename}_{$method['class']}_{$method['method']}_method_harness.php";
        $dir = $outputDir . DIRECTORY_SEPARATOR . $type->name;
        $outputPath = get_output_path($dir, $harnessName);

        $harness = common_harness_header($type);
        $harness .= "require_once '$filepath';\n";

        $argIndex = append_user_input_vars_to_harness($harness, $inputs, $filepath);
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
    }
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
                if (!in_array($token[1], SKIP_FUNCTION_CALLS)) {
                    return true;
                }
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

    foreach (HarnessType::cases() as $type) {
        $harnessName = "{$basename}_inline_harness.php";
        $dir = $outputDir . DIRECTORY_SEPARATOR . $type->name;
        $outputPath = get_output_path($dir, $harnessName);

        $harness = common_harness_header($type);
        append_user_input_vars_to_harness($harness, $inputs, $filepath);

        $harness .= "require_once '$filepath';\n";

        file_put_contents($outputPath, $harness);
    }
}

if ($argc < 2) {
    echo "Usage: php {$argv[0]} <target_directory>\n";
    exit(1);
}
$targetDir = rtrim($argv[1], '/\\');
$outputDir = "$targetDir/" . OUTPUT_FOLDER;

foreach (HarnessType::cases() as $type) {
    $dir = $outputDir . DIRECTORY_SEPARATOR . $type->name;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "Failed to create output directory: $dir\n";
            exit(1);
        }
    } else {
        remove_all_php_files_from_folder($dir);
    }
}

$phpFiles = extract_php_files($targetDir);
$plugin_entry_file = get_plugin_entry_file($targetDir);
if ($plugin_entry_file === '') {
    echo "No plugin entry file found. Please ensure the plugin header is present in one of the files.\n";
    exit(1);
}

$inline_count = 0;
$function_count = 0;
$method_count = 0;
foreach ($phpFiles as $phpFile) {
    $code = file_get_contents($phpFile);

    [$functions, $methods] = extract_functions_and_methods($code);

    if (is_inline_php_file($code)) {
        $inputs = extract_user_input_vars($code);
        if (!empty($inputs)) {
            generate_inline_harness($phpFile, $inputs, $outputDir);
            $inline_count++;
        }
    }

    foreach ($functions as $function) {
        $inputs = extract_user_input_vars($function['body']);
        if (!empty($inputs)) {
            generate_function_harness($phpFile, $function, $inputs, $outputDir);
            $function_count++;
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
            $method_count++;
        }
    }
}

if (($inline_count + $function_count + $method_count) === 0) {
    echo "No harnesses generated for plugin \"$targetDir\".";
} else {
    echo "Successfully generated harnesses for plugin \"$targetDir\".\n";
    echo "Inline: $inline_count\n";
    echo "Function: $function_count\n";
    echo "Method: $method_count\n";
}
