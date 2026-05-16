<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_execute_php')) {
    fwrite(STDERR, "Open Mira execute-php ability is not loaded.\n");
    exit(1);
}

openmira_set_safety_mode('act', 30);
delete_transient('openmira_execute_php_rate_' . get_current_user_id());

$first = openmira_execute_php(['code' => 'return 42;']);
if (is_wp_error($first) || ($first['return_value'] ?? null) !== 42) {
    fwrite(STDERR, "execute-php baseline call failed.\n");
    exit(1);
}

$limit_filter = static fn(int $_max_calls): int => 1;
add_filter('openmira_execute_php_max_calls_per_window', $limit_filter);

$rate_limited = openmira_execute_php(['code' => 'return 43;']);
remove_filter('openmira_execute_php_max_calls_per_window', $limit_filter);

if (!is_wp_error($rate_limited) || $rate_limited->get_error_code() !== 'openmira_execute_php_rate_limited') {
    fwrite(STDERR, "execute-php rate guard did not reject the second call.\n");
    exit(1);
}

delete_transient('openmira_execute_php_rate_' . get_current_user_id());

$memory_filter = static fn(int $_max_memory_delta_bytes): int => 1;
add_filter('openmira_execute_php_max_memory_delta_bytes', $memory_filter);

$memory_limited = openmira_execute_php(['code' => '$x = str_repeat("x", 1048576); return strlen($x);']);
remove_filter('openmira_execute_php_max_memory_delta_bytes', $memory_filter);

if (
    is_wp_error($memory_limited)
    || ($memory_limited['success'] ?? true) !== false
    || (($memory_limited['runaway_guard_error']['code'] ?? '') !== 'openmira_execute_php_memory_delta_exceeded')
) {
    fwrite(STDERR, "execute-php memory guard did not flag a large allocation.\n");
    exit(1);
}

delete_transient('openmira_execute_php_rate_' . get_current_user_id());

echo wp_json_encode([
    'status' => 'ok',
    'baseline_return' => $first['return_value'] ?? null,
    'rate_error' => $rate_limited->get_error_code(),
    'memory_error' => $memory_limited['runaway_guard_error']['code'] ?? '',
], JSON_PRETTY_PRINT) . "\n";
