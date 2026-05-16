<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

foreach ([
    'openmira_create_screenshot_job',
    'openmira_get_screenshot_runner_jobs',
    'openmira_count_pending_screenshot_jobs_for_user',
    'openmira_rest_screenshot_runner_complete',
    'openmira_mark_screenshot_runner_seen',
    'openmira_is_screenshot_runner_active_for_current_user',
] as $function_name) {
    if (!function_exists($function_name)) {
        fwrite(STDERR, "Open Mira screenshot runner function is not loaded: {$function_name}\n");
        exit(1);
    }
}

update_option('openmira_screenshot_jobs', [], false);
delete_transient('openmira_screenshot_runner_seen_' . get_current_user_id());

$job = openmira_create_screenshot_job(home_url('/'), 800, 600, true, [
    'label' => 'Smoke runner',
    'note' => 'wp-env smoke',
]);
$job_id = (string) ($job['job_id'] ?? '');

if ($job_id === '' || openmira_count_pending_screenshot_jobs_for_user() !== 1) {
    fwrite(STDERR, "Screenshot runner did not create one pending job.\n");
    exit(1);
}

$queue_jobs = openmira_get_screenshot_runner_jobs();
if (count($queue_jobs) !== 1 || ($queue_jobs[0]['job_id'] ?? '') !== $job_id) {
    fwrite(STDERR, "Screenshot runner queue did not return the created job.\n");
    exit(1);
}

openmira_mark_screenshot_runner_seen();
if (!openmira_is_screenshot_runner_active_for_current_user()) {
    fwrite(STDERR, "Screenshot runner heartbeat did not mark the runner active.\n");
    exit(1);
}

$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
$request = new WP_REST_Request('POST', '/openmira/v1/screenshot-runner/complete');
$request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode([
    'job_id' => $job_id,
    'mime_type' => 'image/png',
    'image_base64' => $png_base64,
]));

$response = openmira_rest_screenshot_runner_complete($request);
if (is_wp_error($response)) {
    fwrite(STDERR, "Screenshot runner complete returned error: " . $response->get_error_message() . "\n");
    exit(1);
}

$data = $response instanceof WP_REST_Response ? $response->get_data() : null;
if (!is_array($data) || ($data['complete'] ?? false) !== true) {
    fwrite(STDERR, "Screenshot runner complete did not return complete=true.\n");
    exit(1);
}

$completed_jobs = openmira_get_screenshot_runner_jobs($job_id);
if (count($completed_jobs) !== 1 || ($completed_jobs[0]['status'] ?? '') !== 'complete') {
    fwrite(STDERR, "Screenshot runner queue did not show the completed job.\n");
    exit(1);
}
if (!isset($completed_jobs[0]['image_url'], $completed_jobs[0]['resource_uri'])) {
    fwrite(STDERR, "Completed screenshot job did not expose image_url and resource_uri.\n");
    exit(1);
}

update_option('openmira_screenshot_jobs', [], false);
delete_transient('openmira_screenshot_runner_seen_' . get_current_user_id());

echo wp_json_encode([
    'status' => 'ok',
    'job_id' => $job_id,
    'complete' => true,
], JSON_PRETTY_PRINT) . "\n";
