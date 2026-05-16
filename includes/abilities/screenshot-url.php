<?php

declare(strict_types=1);

/**
 * Ability: External screenshot capture jobs.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/screenshot-url', [
    'label' => __('Screenshot URL', domain: 'open-mira'),
    'description' => __(
        'Creates a same-site screenshot job for external Playwright capture. The resulting image is stored on disk for human or CI inspection; MCP agents do not receive image content directly.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'Same-site absolute or relative URL to capture.',
                'minLength' => 1,
            ],
            'viewport_width' => [
                'type' => 'integer',
                'description' => 'Viewport width in CSS pixels.',
                'default' => 1440,
            ],
            'viewport_height' => [
                'type' => 'integer',
                'description' => 'Viewport height in CSS pixels.',
                'default' => 1200,
            ],
            'viewport' => [
                'type' => 'object',
                'description' => 'Alias object for viewport_width/viewport_height, e.g. {"width":1440,"height":1200}.',
                'properties' => [
                    'width' => ['type' => 'integer'],
                    'height' => ['type' => 'integer'],
                ],
                'additionalProperties' => true,
            ],
            'full_page' => [
                'type' => 'boolean',
                'description' => 'Capture the full page when supported by the external bridge.',
                'default' => true,
            ],
            'label' => [
                'type' => 'string',
                'description' => 'Optional human-readable label for this screenshot job.',
            ],
            'note' => [
                'type' => 'string',
                'description' => 'Optional short note describing the screenshot purpose.',
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_screenshot_url',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Creates a screenshot capture job. Capture it externally with scripts/openmira-complete-screenshot-job.sh, which stores the PNG/JPEG under wp-content/openmira-screenshots/ for human or CI inspection. Agents cannot receive image content directly; use a vision-capable client native browser tool for agent-visible captures.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/get-screenshot-job-metadata', [
    'label' => __('Get Screenshot Job Metadata', domain: 'open-mira'),
    'description' => __('Internal bridge metadata read for screenshot jobs.', domain: 'open-mira'),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Screenshot job ID.', 'minLength' => 1],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_get_screenshot_job_metadata',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => false],
        'annotations' => [
            'instructions' => 'Internal Playwright bridge metadata read. Not exposed as a public MCP tool.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/complete-screenshot-url-job', [
    'label' => __('Complete Screenshot URL Job', domain: 'open-mira'),
    'description' => __('Stores PNG or JPEG bytes for an external screenshot job.', domain: 'open-mira'),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Screenshot job ID.', 'minLength' => 1],
            'image_base64' => [
                'type' => 'string',
                'description' => 'Base64 encoded PNG or JPEG image bytes.',
                'minLength' => 1,
            ],
            'mime_type' => [
                'type' => 'string',
                'description' => 'Image MIME type.',
                'enum' => ['image/png', 'image/jpeg'],
                'default' => 'image/png',
            ],
            'error' => ['type' => 'string', 'description' => 'Optional capture error message.', 'default' => ''],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_complete_screenshot_url_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => false],
        'annotations' => [
            'instructions' => 'Internal Playwright bridge step. External scripts call this after screenshot-url creates a job.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

add_action('wp_ajax_openmira_get_screenshot_image', callback: 'openmira_ajax_get_screenshot_image');

/**
 * Create a screenshot job for external capture.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_screenshot_url(array $input): array|WP_Error
{
    $target_url = openmira_normalize_screenshot_url((string) ($input['url'] ?? ''));
    if (is_wp_error($target_url)) {
        return $target_url;
    }

    $viewport = is_array($input['viewport'] ?? null) ? $input['viewport'] : [];
    $viewport_width = max(320, min(3840, (int) ($input['viewport_width'] ?? $viewport['width'] ?? 1440)));
    $viewport_height = max(320, min(4320, (int) ($input['viewport_height'] ?? $viewport['height'] ?? 1200)));
    // @mago-expect analysis:mixed-assignment
    $full_page_input = $input['full_page'] ?? true;
    $full_page = $full_page_input === true || $full_page_input === 'true' || $full_page_input === 1;

    $label = sanitize_text_field((string) ($input['label'] ?? ''));
    $note = sanitize_textarea_field((string) ($input['note'] ?? ''));

    $job = openmira_create_screenshot_job($target_url, $viewport_width, $viewport_height, $full_page, [
        'label' => $label,
        'note' => $note,
    ]);

    $job_id = (string) $job['job_id'];
    return [
        'job' => $job,
        'job_id' => $job_id,
        'target_url' => $target_url,
        'complete_ability' => 'openmira/complete-screenshot-url-job',
        'bridge_script' => 'scripts/openmira-complete-screenshot-job.sh',
        'storage_directory' => 'wp-content/openmira-screenshots/',
        'instructions' => 'Capture this job externally with scripts/openmira-complete-screenshot-job.sh. The bridge reads this job_id, captures target_url with Playwright, and posts the image back through openmira/complete-screenshot-url-job. Open Mira stores the resulting PNG/JPEG on disk for human or CI inspection; MCP agents cannot receive image content directly.',
    ];
}

/**
 * Read screenshot job metadata for the external bridge.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_get_screenshot_job_metadata(array $input): array|WP_Error
{
    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_job_id', 'Provide a screenshot job_id.');
    }

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job)) {
        return new WP_Error('screenshot_job_not_found', 'Screenshot job not found.');
    }

    return [
        'job' => openmira_public_screenshot_job($job, include_path: true),
        'complete' => ($job['status'] ?? '') === 'complete',
    ];
}

/**
 * Complete an external screenshot job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_complete_screenshot_url_job(array $input): array|WP_Error
{
    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_job_id', 'Provide a screenshot job_id.');
    }

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job)) {
        return new WP_Error('screenshot_job_not_found', 'Screenshot job not found.');
    }

    $error = sanitize_textarea_field((string) ($input['error'] ?? ''));
    if ($error !== '') {
        $updated = openmira_update_screenshot_job($job_id, [
            'status' => 'failed',
            'error' => $error,
            'completed_at' => time(),
        ]);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return [
            'job' => openmira_public_screenshot_job($updated, include_path: true),
            'complete' => false,
        ];
    }

    $mime_type = (string) ($input['mime_type'] ?? 'image/png');
    if (!in_array($mime_type, ['image/png', 'image/jpeg'], strict: true)) {
        return new WP_Error('unsupported_image_type', 'Only image/png and image/jpeg screenshots are supported.');
    }

    $image_base64 = openmira_normalize_base64_image((string) ($input['image_base64'] ?? ''));
    if ($image_base64 === '') {
        return new WP_Error('missing_image_base64', 'Provide image_base64 or an error message.');
    }

    $bytes = base64_decode($image_base64, strict: true);
    if (!is_string($bytes)) {
        return new WP_Error('invalid_image_base64', 'Screenshot image_base64 is not valid base64.');
    }
    if (strlen($bytes) > 8_000_000) {
        return new WP_Error('screenshot_too_large', 'Screenshot payload is larger than 8 MB.');
    }
    if (!openmira_image_bytes_match_mime($bytes, $mime_type)) {
        return new WP_Error('invalid_image_bytes', 'Screenshot bytes do not match the declared MIME type.');
    }

    $path = openmira_get_screenshot_image_path($job_id, $mime_type);
    if (is_wp_error($path)) {
        return $path;
    }
    if (file_put_contents($path, $bytes) === false) {
        return new WP_Error('screenshot_write_failed', 'Could not write screenshot file.');
    }

    $updated = openmira_update_screenshot_job($job_id, [
        'status' => 'complete',
        'error' => '',
        'mime_type' => $mime_type,
        'byte_count' => strlen($bytes),
        'image_hash' => hash('sha256', $bytes),
        'image_path' => $path,
        'completed_at' => time(),
    ]);
    if (is_wp_error($updated)) {
        return $updated;
    }

    openmira_record_audit_event([
        'ability' => 'openmira/complete-screenshot-url-job',
        'operation' => 'complete',
        'target_path' => (string) ($updated['target_url'] ?? ''),
        'status' => 'success',
        'job_id' => $job_id,
        'mime_type' => $mime_type,
        'byte_count' => strlen($bytes),
    ]);

    return [
        'job' => openmira_public_screenshot_job($updated, include_path: true),
        'complete' => true,
        'image_url' => openmira_get_screenshot_image_url($job_id),
        'image_path' => $path,
        'instructions' => 'Screenshot stored on disk. Use the local file path for human or CI inspection; MCP agents do not receive image content directly.',
    ];
}

/**
 * Create and persist a screenshot job.
 *
 * @return array<array-key, mixed>
 */
function openmira_create_screenshot_job(
    string $target_url,
    int $viewport_width,
    int $viewport_height,
    bool $full_page,
    array $metadata = [],
): array {
    // @mago-expect analysis:mixed-assignment
    $uuid = wp_generate_uuid4();
    $job_id = is_string($uuid)
        ? str_replace(search: '-', replace: '', subject: $uuid)
        : hash('sha256', uniqid('openmira_screenshot', more_entropy: true));
    $job = [
        'job_id' => $job_id,
        'status' => 'pending',
        'target_url' => $target_url,
        'viewport_width' => $viewport_width,
        'viewport_height' => $viewport_height,
        'full_page' => $full_page,
        'created_by' => get_current_user_id(),
        'label' => (string) ($metadata['label'] ?? ''),
        'note' => (string) ($metadata['note'] ?? ''),
        'mime_type' => 'image/png',
        'byte_count' => 0,
        'image_hash' => '',
        'image_path' => '',
        'error' => '',
        'created_at' => time(),
        'completed_at' => 0,
    ];

    $jobs = openmira_get_screenshot_jobs();
    $jobs[$job_id] = $job;
    openmira_save_screenshot_jobs($jobs);

    return openmira_public_screenshot_job($job, include_path: false);
}

/**
 * Return one stored screenshot job.
 *
 * @return array<array-key, mixed>|null
 */
function openmira_get_screenshot_job(string $job_id): ?array
{
    $jobs = openmira_get_screenshot_jobs();
    $job = $jobs[$job_id] ?? null;
    return is_array($job) ? $job : null;
}

/**
 * Update one screenshot job.
 *
 * @param array<string, mixed> $updates
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_update_screenshot_job(string $job_id, array $updates): array|WP_Error
{
    $jobs = openmira_get_screenshot_jobs();
    if (!is_array($jobs[$job_id] ?? null)) {
        return new WP_Error('screenshot_job_not_found', 'Screenshot job not found.');
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($updates as $key => $value) {
        $jobs[$job_id][$key] = $value;
    }
    openmira_save_screenshot_jobs($jobs);

    return $jobs[$job_id];
}

/**
 * Return a protected URL for the captured image.
 */
function openmira_get_screenshot_image_url(string $job_id): string
{
    return add_query_arg([
        'action' => 'openmira_get_screenshot_image',
        'job_id' => rawurlencode($job_id),
        '_wpnonce' => wp_create_nonce('openmira_screenshot_image_' . $job_id),
    ], admin_url('admin-ajax.php'));
}

/**
 * Return stored screenshot jobs.
 *
 * @return array<string, array<array-key, mixed>>
 */
function openmira_get_screenshot_jobs(): array
{
    // @mago-expect analysis:mixed-assignment
    $jobs = get_option('openmira_screenshot_jobs', default_value: []);
    if (!is_array($jobs)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($jobs as $job_id => $job) {
        if (!is_string($job_id) || !is_array($job)) {
            continue;
        }
        $normalized[$job_id] = $job;
    }

    return $normalized;
}

/**
 * Save screenshot jobs after pruning old entries.
 *
 * @param array<string, array<array-key, mixed>> $jobs
 */
function openmira_save_screenshot_jobs(array $jobs): void
{
    $now = time();
    foreach ($jobs as $job_id => $job) {
        $created_at = (int) ($job['created_at'] ?? $now);
        if ($created_at < ($now - 86_400)) {
            unset($jobs[$job_id]);
        }
    }

    uasort(
        $jobs,
        static fn(array $a, array $b): int => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0),
    );
    $jobs = array_slice($jobs, offset: 0, length: 25, preserve_keys: true);

    update_option('openmira_screenshot_jobs', $jobs, autoload: false);
}

/**
 * Normalize a screenshot job for output.
 *
 * @param array<array-key, mixed> $job
 * @return array<string, mixed>
 */
function openmira_public_screenshot_job(array $job, bool $include_path): array
{
    $public = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($job as $key => $value) {
        if ($key === 'image_path' && !$include_path) {
            continue;
        }
        $public[(string) $key] = is_scalar($value) ? $value : '';
    }

    return $public;
}

/**
 * Serve a completed screenshot image to authenticated clients.
 */
function openmira_ajax_get_screenshot_image(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', title: '', args: ['response' => 403]);
    }

    $job_id_input = $_GET['job_id'] ?? '';
    $job_id = sanitize_key(is_string($job_id_input) ? $job_id_input : '');
    if ($job_id === '') {
        wp_die('Missing job_id', title: '', args: ['response' => 400]);
    }

    check_ajax_referer('openmira_screenshot_image_' . $job_id);

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job) || ($job['status'] ?? '') !== 'complete') {
        wp_die('Screenshot not found', title: '', args: ['response' => 404]);
    }

    $path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        wp_die('Screenshot file not found', title: '', args: ['response' => 404]);
    }

    $mime_type = is_string($job['mime_type'] ?? null) ? $job['mime_type'] : 'image/png';
    if (!in_array($mime_type, ['image/png', 'image/jpeg'], strict: true)) {
        wp_die('Unsupported image type', title: '', args: ['response' => 415]);
    }

    nocache_headers();
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit();
}

/**
 * Normalize a same-site screenshot URL.
 */
function openmira_normalize_screenshot_url(string $url): string|WP_Error
{
    $url = trim($url);
    if ($url === '') {
        return new WP_Error('missing_url', 'Provide a URL to screenshot.');
    }

    $absolute = wp_http_validate_url($url) ? $url : home_url($url[0] === '/' ? $url : '/' . $url);
    // @mago-expect analysis:mixed-assignment
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    // @mago-expect analysis:mixed-assignment
    $target_host = wp_parse_url($absolute, PHP_URL_HOST);

    if (!is_string($site_host) || !is_string($target_host) || strtolower($site_host) !== strtolower($target_host)) {
        if (apply_filters('openmira_allow_external_screenshot_urls', false, $url) !== true) {
            return new WP_Error('external_url_not_allowed', 'Screenshots are limited to same-site URLs.');
        }
    }

    return $absolute;
}

/**
 * Remove data URL prefixes and whitespace from base64 image strings.
 */
function openmira_normalize_base64_image(string $image_base64): string
{
    if (str_starts_with($image_base64, 'data:image/')) {
        $comma = strpos($image_base64, needle: ',');
        $image_base64 = $comma === false ? '' : substr($image_base64, $comma + 1);
    }

    return preg_replace('/\s+/', replacement: '', subject: $image_base64) ?? '';
}

/**
 * Return whether image bytes match the declared MIME type.
 */
function openmira_image_bytes_match_mime(string $bytes, string $mime_type): bool
{
    return match ($mime_type) {
        'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
        'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
        default => false,
    };
}

/**
 * Return a writable screenshot image path.
 */
function openmira_get_screenshot_image_path(string $job_id, string $mime_type): string|WP_Error
{
    $directory = trailingslashit(WP_CONTENT_DIR) . 'openmira-screenshots';
    if (!wp_mkdir_p($directory)) {
        return new WP_Error('screenshot_directory_failed', 'Could not create screenshot directory.');
    }

    $extension = $mime_type === 'image/jpeg' ? 'jpg' : 'png';
    return trailingslashit($directory) . $job_id . '.' . $extension;
}
