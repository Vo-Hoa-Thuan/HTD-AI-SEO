<?php
// Read-only static tokenizer for PHP files using token_get_all()
if ($argc < 2) {
    fwrite(STDERR, "Usage: php php_ast_helper.php <file>\n");
    exit(1);
}
$file = $argv[1];
$code = file_get_contents($file);
if ($code === false) {
    fwrite(STDERR, "Cannot read file\n");
    exit(1);
}

$tokens = token_get_all($code);
$result = [
    'namespaces' => [],
    'classes' => [],
    'interfaces' => [],
    'traits' => [],
    'functions' => [],
    'methods' => [],
    'constructors' => [],
    'static_methods' => [],
    'constants' => [],
    'globals' => [],
    'includes' => [],
    'dynamic_callbacks' => [],
    'hooks' => [],
    'ajax_actions' => [],
    'rest_routes' => [],
    'dangerous_calls' => [],
    'external_calls' => [],
    'db_calls' => [],
    'options_calls' => [],
    'meta_calls' => [],
    'post_mutations' => [],
    'file_ops' => [],
    'plugin_header' => null,
];

// Check for plugin header in first 8KB
$head = substr($code, 0, 8192);
if (preg_match('/Plugin Name:\s*(.+)/i', $head)) {
    $headers = ['Plugin Name', 'Plugin URI', 'Description', 'Version', 'Author', 'Author URI', 'License', 'License URI', 'Text Domain', 'Domain Path', 'Requires at least', 'Requires PHP'];
    $ph = [];
    foreach ($headers as $h) {
        if (preg_match('/^\s*\*?\s*' . preg_quote($h, '/') . ':\s*(.+)$/mi', $head, $m)) {
            $ph[$h] = trim($m[1]);
        }
    }
    $result['plugin_header'] = $ph;
}

$count = count($tokens);
$currentClass = null;
$braceDepth = 0;
$classBraceDepth = null;

$dangerousFuncs = ['eval', 'assert', 'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'unserialize', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13', 'create_function', 'preg_replace'];
$externalFuncs = ['wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head', 'curl_init', 'curl_exec', 'file_get_contents', 'fsockopen'];
$dbFuncs = ['query', 'get_results', 'get_row', 'get_var', 'get_col', 'insert', 'update', 'delete', 'replace', 'prepare'];
$optionFuncs = ['get_option', 'update_option', 'add_option', 'delete_option', 'get_transient', 'set_transient', 'delete_transient'];
$metaFuncs = ['get_post_meta', 'update_post_meta', 'add_post_meta', 'delete_post_meta', 'get_term_meta', 'update_term_meta', 'get_user_meta', 'update_user_meta'];
$postMutFuncs = ['wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post', 'wp_publish_post', 'wp_set_object_terms', 'wp_set_post_terms', 'wp_set_post_categories', 'wp_set_post_tags'];
$fileOpFuncs = ['file_put_contents', 'fopen', 'fwrite', 'unlink', 'rmdir', 'mkdir', 'rename', 'copy', 'move_uploaded_file', 'wp_handle_upload', 'media_handle_sideload', 'wp_upload_bits'];
$callbackFuncs = ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'usort', 'uasort', 'uksort'];

function nextNonWs($tokens, $i, $count) {
    for ($j = $i + 1; $j < $count; $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) continue;
        return $j;
    }
    return -1;
}

$isStatic = false;
for ($i = 0; $i < $count; $i++) {
    $t = $tokens[$i];
    if (!is_array($t)) {
        if ($t === '{') $braceDepth++;
        elseif ($t === '}') {
            $braceDepth--;
            if ($classBraceDepth !== null && $braceDepth < $classBraceDepth) {
                $currentClass = null;
                $classBraceDepth = null;
            }
        }
        continue;
    }
    [$id, $text, $line] = $t;
    if ($id === T_STATIC) {
        $isStatic = true;
    } elseif ($id === T_NAMESPACE) {
        $ns = '';
        $j = nextNonWs($tokens, $i, $count);
        while ($j !== -1 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
            $ns .= $tokens[$j][1];
            $j++;
        }
        if ($ns !== '') $result['namespaces'][] = ['name' => $ns, 'line' => $line];
    } elseif ($id === T_CLASS) {
        // check not ::class or anonymous class
        $j = nextNonWs($tokens, $i, $count);
        if ($j !== -1 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $currentClass = $tokens[$j][1];
            $classBraceDepth = $braceDepth + 1;
            $result['classes'][] = ['name' => $currentClass, 'line' => $line];
        }
    } elseif ($id === T_INTERFACE) {
        $j = nextNonWs($tokens, $i, $count);
        if ($j !== -1 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $currentClass = $tokens[$j][1];
            $classBraceDepth = $braceDepth + 1;
            $result['interfaces'][] = ['name' => $currentClass, 'line' => $line];
        }
    } elseif ($id === T_TRAIT) {
        $j = nextNonWs($tokens, $i, $count);
        if ($j !== -1 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $currentClass = $tokens[$j][1];
            $classBraceDepth = $braceDepth + 1;
            $result['traits'][] = ['name' => $currentClass, 'line' => $line];
        }
    } elseif ($id === T_FUNCTION) {
        $j = nextNonWs($tokens, $i, $count);
        if ($j !== -1 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $fn = $tokens[$j][1];
            if ($currentClass !== null) {
                $result['methods'][] = ['class' => $currentClass, 'name' => $fn, 'line' => $line, 'static' => $isStatic];
                if ($fn === '__construct') {
                    $result['constructors'][] = ['class' => $currentClass, 'line' => $line];
                }
                if ($isStatic) {
                    $result['static_methods'][] = ['class' => $currentClass, 'name' => $fn, 'line' => $line];
                }
            } else {
                $result['functions'][] = ['name' => $fn, 'line' => $line];
            }
        }
        $isStatic = false;
    } elseif ($id === T_CONST) {
        $j = nextNonWs($tokens, $i, $count);
        if ($j !== -1 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $result['constants'][] = ['name' => $tokens[$j][1], 'class' => $currentClass, 'line' => $line];
        }
    } elseif ($id === T_GLOBAL) {
        $j = nextNonWs($tokens, $i, $count);
        while ($j !== -1 && $tokens[$j] !== ';') {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                $result['globals'][] = ['name' => $tokens[$j][1], 'line' => $line];
            }
            $j++;
        }
    } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
        $snippet = '';
        $j = $i;
        while ($j < $count && $tokens[$j] !== ';' && strlen($snippet) < 200) {
            $snippet .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $j++;
        }
        $result['includes'][] = ['type' => $text, 'expr' => trim(preg_replace('/\s+/', ' ', $snippet)), 'line' => $line];
    } elseif ($id === T_STRING) {
        $fnLower = strtolower($text);
        $j = nextNonWs($tokens, $i, $count);
        $isCall = ($j !== -1 && $tokens[$j] === '(');
        if ($isCall) {
            // Grab first ~160 chars of args
            $args = '';
            $k = $j + 1;
            $pDepth = 1;
            while ($k < $count && $pDepth > 0 && strlen($args) < 160) {
                $tk = $tokens[$k];
                if ($tk === '(') $pDepth++;
                elseif ($tk === ')') $pDepth--;
                if ($pDepth > 0) $args .= is_array($tk) ? $tk[1] : $tk;
                $k++;
            }
            $argsClean = trim(preg_replace('/\s+/', ' ', $args));

            if ($fnLower === 'define') {
                $result['constants'][] = ['name' => $argsClean, 'class' => null, 'line' => $line];
            } elseif (in_array($fnLower, ['add_action', 'add_filter', 'do_action', 'apply_filters', 'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook', 'wp_schedule_event', 'wp_schedule_single_event'])) {
                $result['hooks'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
                if (strpos($argsClean, 'wp_ajax_') !== false) {
                    $result['ajax_actions'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
                }
            } elseif ($fnLower === 'register_rest_route') {
                $result['rest_routes'][] = ['args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $dangerousFuncs)) {
                $result['dangerous_calls'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $externalFuncs)) {
                $result['external_calls'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $dbFuncs)) {
                $result['db_calls'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $optionFuncs)) {
                $result['options_calls'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $metaFuncs)) {
                $result['meta_calls'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $postMutFuncs)) {
                $result['post_mutations'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $fileOpFuncs)) {
                $result['file_ops'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            } elseif (in_array($fnLower, $callbackFuncs)) {
                $result['dynamic_callbacks'][] = ['func' => $fnLower, 'args' => $argsClean, 'line' => $line];
            }
        }
        if (!in_array($id, [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT])) {
            $isStatic = false;
        }
    } elseif (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT])) {
        $isStatic = false;
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
