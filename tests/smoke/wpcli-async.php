<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_run_wpcli') || !function_exists('openmira_get_wpcli_job')) {
    fwrite(STDERR, "Open Mira WP-CLI abilities are not loaded.\n");
    exit(1);
}

openmira_set_safety_mode('act', 30);
if (!defined('OPENMIRA_WPCLI_ALLOW_ROOT')) {
    define('OPENMIRA_WPCLI_ALLOW_ROOT', true);
}

$job = openmira_run_wpcli([
    'command' => 'option get siteurl',
    'mode' => 'async',
    'timeout_seconds' => 30,
]);

if (is_wp_error($job)) {
    fwrite(STDERR, $job->get_error_code() . ': ' . $job->get_error_message() . "\n");
    exit(1);
}

$job_id = (string) ($job['job_id'] ?? '');
if ($job_id === '') {
    fwrite(STDERR, "Async WP-CLI did not return a job_id.\n");
    exit(1);
}

$status = null;
for ($attempt = 0; $attempt < 10; $attempt++) {
    $status = openmira_get_wpcli_job(['job_id' => $job_id]);
    if (is_wp_error($status)) {
        fwrite(STDERR, $status->get_error_message() . "\n");
        exit(1);
    }
    if (($status['status'] ?? '') !== 'running') {
        break;
    }
    sleep(1);
}

if (!is_array($status) || ($status['status'] ?? '') !== 'complete' || ($status['exit_code'] ?? null) !== 0) {
    fwrite(STDERR, "Async WP-CLI job did not complete successfully.\n");
    fwrite(STDERR, wp_json_encode($status, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

if (!str_contains((string) ($status['log'] ?? ''), home_url())) {
    fwrite(STDERR, "Async WP-CLI log did not contain siteurl output.\n");
    exit(1);
}

echo
    wp_json_encode([
        'status' => 'ok',
        'job_id' => $job_id,
        'log_size' => $status['log_size'] ?? 0,
    ], JSON_PRETTY_PRINT) . "\n"
;
