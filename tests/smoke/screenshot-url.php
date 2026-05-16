<?php

$required = [
    'openmira_screenshot_url',
    'openmira_get_screenshot_job_metadata',
    'openmira_complete_screenshot_url_job',
];
foreach ($required as $function_name) {
    if (!function_exists($function_name)) {
        fwrite(STDERR, "Open Mira screenshot function is not loaded: {$function_name}\n");
        exit(1);
    }
}

update_option('openmira_screenshot_jobs', [], false);

$created = openmira_screenshot_url([
    'url' => home_url('/'),
    'viewport' => ['width' => 800, 'height' => 600],
    'full_page' => true,
    'label' => 'Smoke screenshot',
]);
if (is_wp_error($created)) {
    fwrite(STDERR, $created->get_error_message() . PHP_EOL);
    exit(1);
}

$job_id = (string) ($created['job_id'] ?? '');
$removed_fields = ['runner_queue' . '_url', 'runner' . '_url', 'resource' . '_uri'];
$has_removed_field = false;
foreach ($removed_fields as $field) {
    if (isset($created[$field])) {
        $has_removed_field = true;
        break;
    }
}
if ($job_id === '' || $has_removed_field) {
    fwrite(STDERR, "Screenshot job response still contains removed runner/resource fields.\n");
    exit(1);
}

$metadata = openmira_get_screenshot_job_metadata(['job_id' => $job_id]);
if (is_wp_error($metadata) || ($metadata['job']['target_url'] ?? '') !== home_url('/')) {
    fwrite(STDERR, "Screenshot metadata helper did not return the created job.\n");
    exit(1);
}

$complete = openmira_complete_screenshot_url_job([
    'job_id' => $job_id,
    'mime_type' => 'image/png',
    'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
]);
if (is_wp_error($complete) || ($complete['complete'] ?? false) !== true) {
    fwrite(STDERR, is_wp_error($complete) ? $complete->get_error_message() : 'Screenshot completion failed.' . PHP_EOL);
    exit(1);
}

$image_path = (string) ($complete['image_path'] ?? ($complete['job']['image_path'] ?? ''));
if ($image_path === '' || !is_file($image_path) || str_contains(wp_normalize_path($image_path), 'openmira-screenshots') === false) {
    fwrite(STDERR, "Completed screenshot did not write an image under openmira-screenshots.\n");
    exit(1);
}

@unlink($image_path);
update_option('openmira_screenshot_jobs', [], false);

echo wp_json_encode([
    'status' => 'ok',
    'job_id' => $job_id,
    'image_path' => $image_path,
], JSON_PRETTY_PRINT) . PHP_EOL;
