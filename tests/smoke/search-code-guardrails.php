<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_search_code')) {
    fwrite(STDERR, "Open Mira search-code ability is not loaded.\n");
    exit(1);
}

$broad = openmira_search_code([
    'query' => 'function',
    'path' => 'wp-content',
]);

if (!is_wp_error($broad) || $broad->get_error_code() !== 'search_scope_too_broad') {
    fwrite(STDERR, "Broad wp-content search was not rejected.\n");
    exit(1);
}

$too_many = openmira_search_code([
    'query' => 'function',
    'path' => 'wp-content',
    'allow_broad_scan' => true,
    'max_candidate_files' => 1,
]);

if (!is_wp_error($too_many) || $too_many->get_error_code() !== 'too_many_candidate_files') {
    fwrite(STDERR, "Explicit broad search did not enforce the candidate file cap.\n");
    exit(1);
}

$scoped = openmira_search_code([
    'query' => 'openmira_search_code',
    'path' => 'wp-content/plugins/openmira/includes/abilities/search-code.php',
    'limit' => 3,
]);

if (is_wp_error($scoped)) {
    fwrite(STDERR, $scoped->get_error_message() . "\n");
    exit(1);
}

if (($scoped['match_count'] ?? 0) < 1 || ($scoped['candidate_file_count'] ?? 0) !== 1) {
    fwrite(STDERR, "Scoped file search did not return the expected match shape.\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'broad_error' => $broad->get_error_code(),
    'cap_error' => $too_many->get_error_code(),
    'scoped_matches' => $scoped['match_count'] ?? 0,
], JSON_PRETTY_PRINT) . "\n";
