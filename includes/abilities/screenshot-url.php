<?php

declare(strict_types=1);

/**
 * Ability: Browser-assisted URL screenshots.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/screenshot-url', [
    'label' => __('Screenshot URL', domain: 'open-mira'),
    'description' => __(
        'Creates a browser-assisted screenshot job for a same-site URL and viewport.',
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
                'description' => 'Capture the full page when supported.',
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
            'instructions' => 'Create this before visual iteration. If the user has the Screenshot Runner tab open, jobs auto-capture. Otherwise use runner_url or a browser bridge, then read the job.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/read-screenshot-url-job', [
    'label' => __('Read Screenshot URL Job', domain: 'open-mira'),
    'description' => __(
        'Reads a browser-assisted screenshot job and optionally returns stored image bytes.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Screenshot job ID.', 'minLength' => 1],
            'include_image' => [
                'type' => 'boolean',
                'description' => 'Return base64 image data only when the image is below inline_image_max_bytes. Prefer image_url/resource_uri or the bridge script screenshot_file path.',
                'default' => false,
            ],
            'inline_image_max_bytes' => [
                'type' => 'integer',
                'description' => 'Maximum image byte size allowed for inline base64 when include_image=true. Values above Open Mira’s safety cap are clamped instead of schema-failing.',
                'default' => 200_000,
                'minimum' => 1024,
            ],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_read_screenshot_url_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Poll this after browser completion. Prefer the returned resource_uri or image_url; request image_base64 only when a client cannot read MCP image resources.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/complete-screenshot-url-job', [
    'label' => __('Complete Screenshot URL Job', domain: 'open-mira'),
    'description' => __('Stores PNG or JPEG bytes for a browser-assisted screenshot job.', domain: 'open-mira'),
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
            'instructions' => 'Internal browser-bridge step. Prefer screenshot-url followed by read-screenshot-url-job.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

add_action('wp_ajax_openmira_get_screenshot_image', callback: 'openmira_ajax_get_screenshot_image');
add_filter(
    'mcp_adapter_default_server_config',
    callback: 'openmira_add_screenshot_resources_to_mcp_config',
    priority: 20,
);

/**
 * Create a browser-assisted screenshot job.
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
    $viewport_width = openmira_clamp_int(
        (int) ($input['viewport_width'] ?? $viewport['width'] ?? 1440),
        min: 320,
        max: 3840,
    );
    $viewport_height = openmira_clamp_int(
        (int) ($input['viewport_height'] ?? $viewport['height'] ?? 1200),
        min: 320,
        max: 4320,
    );
    // @mago-expect analysis:mixed-assignment
    $full_page_input = $input['full_page'] ?? true;
    $full_page = $full_page_input === true || $full_page_input === 'true' || $full_page_input === 1;

    $label = sanitize_text_field((string) ($input['label'] ?? ''));
    $note = sanitize_textarea_field((string) ($input['note'] ?? ''));

    $job = openmira_create_screenshot_job($target_url, $viewport_width, $viewport_height, $full_page, [
        'label' => $label,
        'note' => $note,
    ]);

    return [
        'job' => $job,
        'target_url' => $target_url,
        'runner_queue_url' => openmira_get_screenshot_queue_url(),
        'runner_url' => openmira_get_screenshot_job_url((string) $job['job_id']),
        'complete_ability' => 'openmira/complete-screenshot-url-job',
        'read_ability' => 'openmira/read-screenshot-url-job',
        'instructions' => 'Tell the user to keep their Screenshot Runner tab open at runner_queue_url; jobs created while it is open auto-capture. If the tab is not open, use runner_url for this job or complete it through browser automation. Prefer image_url/resource_uri over inline image_base64.',
        'screenshot_note' => 'WordPress/PHP cannot capture pixels natively; this job closes the loop through an authenticated browser client.',
    ];
}

/**
 * Read a browser-assisted screenshot job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_read_screenshot_url_job(array $input): array|WP_Error
{
    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_job_id', 'Provide a screenshot job_id.');
    }

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job)) {
        return new WP_Error('screenshot_job_not_found', 'Screenshot job not found.');
    }

    // @mago-expect analysis:mixed-assignment
    $include_image_input = $input['include_image'] ?? false;
    $include_image = $include_image_input === true || $include_image_input === 'true' || $include_image_input === 1;
    $inline_image_max_bytes = max(1024, min(1_048_576, (int) ($input['inline_image_max_bytes'] ?? 200_000)));
    $response = [
        'job' => openmira_public_screenshot_job($job, include_path: false),
        'complete' => ($job['status'] ?? '') === 'complete',
        'runner_queue_url' => openmira_get_screenshot_queue_url(),
        'runner_url' => openmira_get_screenshot_job_url($job_id),
        'resource_uri' => openmira_get_screenshot_job_resource_uri($job_id),
    ];

    if (($job['status'] ?? '') === 'complete') {
        $response['image_url'] = openmira_get_screenshot_image_url($job_id);
        $response['image_base64_available_on_request'] = true;
        $response['inline_image_max_bytes'] = $inline_image_max_bytes;
        $response['resource_hint'] = 'Read resource_uri through MCP resources/read when the client supports image resources. Use image_url for cookie-auth browser clients. Use include_image only as a fallback.';
        $response['inline_image_warning'] = 'Inline base64 is capped by inline_image_max_bytes to prevent context blowups. Prefer resource_uri, image_url, or the bridge screenshot_file.';
    }

    if ($include_image && ($job['status'] ?? '') === 'complete') {
        $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
        if ($image_path !== '' && is_file($image_path) && is_readable($image_path)) {
            $bytes = file_get_contents($image_path);
            if (is_string($bytes)) {
                $byte_count = strlen($bytes);
                if ($byte_count > $inline_image_max_bytes) {
                    $response['inline_image_refused'] = true;
                    $response['inline_image_refused_reason'] = sprintf(
                        'Screenshot is %d bytes, above inline_image_max_bytes=%d. Use resource_uri, image_url, or the bridge screenshot_file instead.',
                        $byte_count,
                        $inline_image_max_bytes,
                    );
                    $response['byte_count'] = $byte_count;
                    return $response;
                }

                $response['image_base64'] = base64_encode($bytes);
                $response['mime_type'] = (string) ($job['mime_type'] ?? 'image/png');
                $response['byte_count'] = $byte_count;
            }
        }
    }

    return $response;
}

/**
 * Complete a browser-assisted screenshot job.
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
            'job' => openmira_public_screenshot_job($updated, include_path: false),
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
        'job' => openmira_public_screenshot_job($updated, include_path: false),
        'complete' => true,
        'image_url' => openmira_get_screenshot_image_url($job_id),
        'resource_uri' => openmira_get_screenshot_job_resource_uri($job_id),
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
 * Return the persistent screenshot runner queue URL.
 */
function openmira_get_screenshot_queue_url(): string
{
    return admin_url('admin.php?page=openmira-screenshot-tools');
}

/**
 * Return the screenshot runner URL.
 */
function openmira_get_screenshot_job_url(string $job_id): string
{
    return admin_url('admin.php?page=openmira-screenshot-tools&job=' . rawurlencode($job_id));
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
 * Return the MCP Resource URI for a stored screenshot job image.
 */
function openmira_get_screenshot_job_resource_uri(string $job_id): string
{
    return 'openmira://screenshot-url-jobs/' . rawurlencode($job_id) . '/image';
}

/**
 * Add completed screenshot images as concrete MCP resources on each server request.
 *
 * The MCP Adapter matches resources by exact URI, so dynamic job screenshots are registered
 * from persisted jobs during server construction.
 */
function openmira_add_screenshot_resources_to_mcp_config(mixed $config): mixed
{
    if (!is_array($config)) {
        return $config;
    }

    // @mago-expect analysis:mixed-assignment
    $resources = $config['resources'] ?? [];
    if (!is_array($resources)) {
        $resources = [];
    }

    $config['resources'] = array_merge($resources, openmira_get_screenshot_mcp_resources());
    return $config;
}

/**
 * Build MCP resources for completed screenshot jobs.
 *
 * @return list<object>
 */
function openmira_get_screenshot_mcp_resources(): array
{
    if (!class_exists(\WP\MCP\Domain\Resources\McpResource::class)) {
        return [];
    }

    $resources = [];
    foreach (openmira_get_screenshot_jobs() as $job_id => $job) {
        if (($job['status'] ?? '') !== 'complete') {
            continue;
        }

        $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
        if ($image_path === '' || !is_file($image_path) || !is_readable($image_path)) {
            continue;
        }

        $mime_type = is_string($job['mime_type'] ?? null) ? $job['mime_type'] : 'image/png';
        if (!in_array($mime_type, ['image/png', 'image/jpeg'], strict: true)) {
            continue;
        }

        $resource = \WP\MCP\Domain\Resources\McpResource::fromArray([
            'uri' => openmira_get_screenshot_job_resource_uri($job_id),
            'name' => 'openmira-screenshot-' . $job_id,
            'title' => openmira_screenshot_resource_title($job),
            'description' => 'Captured Open Mira screenshot image. Prefer this resource over inline base64 in tool responses.',
            'mimeType' => $mime_type,
            'size' => (int) ($job['byte_count'] ?? filesize($image_path)),
            'handler' => 'openmira_read_screenshot_image_resource',
            'permission' => static fn(): bool => openmira_permission_bool('openmira/screenshot-url'),
        ]);

        if (!is_wp_error($resource)) {
            $resources[] = $resource;
        }
    }

    return $resources;
}

/**
 * Return a readable title for a screenshot resource.
 *
 * @param array<array-key, mixed> $job
 */
function openmira_screenshot_resource_title(array $job): string
{
    $label = trim((string) ($job['label'] ?? ''));
    if ($label !== '') {
        return 'Open Mira Screenshot: ' . $label;
    }

    $target_url = trim((string) ($job['target_url'] ?? ''));
    if ($target_url !== '') {
        return 'Open Mira Screenshot: ' . $target_url;
    }

    return 'Open Mira Screenshot';
}

/**
 * Read a screenshot image resource.
 *
 * @param array<string, mixed> $arguments
 * @return array<int, array<string, string>>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_read_screenshot_image_resource(array $arguments): array|WP_Error
{
    $uri = is_string($arguments['uri'] ?? null) ? $arguments['uri'] : '';
    $matches = [];
    if (preg_match('#^openmira://screenshot-url-jobs/([^/]+)/image$#', $uri, $matches) !== 1) {
        return new WP_Error('invalid_screenshot_resource_uri', 'Screenshot resource URI is invalid.');
    }

    $job_id = sanitize_key(rawurldecode($matches[1]));
    if ($job_id === '') {
        return new WP_Error('invalid_screenshot_job_id', 'Screenshot resource job ID is invalid.');
    }

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job) || ($job['status'] ?? '') !== 'complete') {
        return new WP_Error('screenshot_job_not_complete', 'Screenshot job is not complete.');
    }

    $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
    if ($image_path === '' || !is_file($image_path) || !is_readable($image_path)) {
        return new WP_Error('screenshot_image_not_found', 'Screenshot image file was not found.');
    }

    $mime_type = is_string($job['mime_type'] ?? null) ? $job['mime_type'] : 'image/png';
    if (!in_array($mime_type, ['image/png', 'image/jpeg'], strict: true)) {
        return new WP_Error('unsupported_image_type', 'Only image/png and image/jpeg screenshots are supported.');
    }

    $bytes = file_get_contents($image_path);
    if (!is_string($bytes)) {
        return new WP_Error('screenshot_image_read_failed', 'Could not read screenshot image bytes.');
    }

    return [[
        'uri' => openmira_get_screenshot_job_resource_uri($job_id),
        'blob' => base64_encode($bytes),
        'mimeType' => $mime_type,
    ]];
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
// @mago-expect lint:cyclomatic-complexity
function openmira_save_screenshot_jobs(array $jobs): void
{
    $cutoff = time() - 86_400;
    foreach ($jobs as $job_id => $job) {
        $created_at = (int) ($job['created_at'] ?? 0);
        if ($created_at > 0 && $created_at >= $cutoff) {
            continue;
        }
        $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
        if ($image_path !== '' && is_file($image_path)) {
            unlink($image_path);
        }
        unset($jobs[$job_id]);
    }
    if (count($jobs) > 25) {
        uasort(
            $jobs,
            static fn(array $a, array $b): int => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0),
        );
        $removed = array_slice($jobs, offset: 25, preserve_keys: true);
        foreach ($removed as $job) {
            $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
            if ($image_path !== '' && is_file($image_path)) {
                unlink($image_path);
            }
        }
        $jobs = array_slice($jobs, offset: 0, length: 25, preserve_keys: true);
    }

    update_option('openmira_screenshot_jobs', $jobs, autoload: false);
}

/**
 * Normalize a screenshot job for public output.
 *
 * @param array<array-key, mixed> $job
 * @return array<array-key, mixed>
 */
function openmira_public_screenshot_job(array $job, bool $include_path): array
{
    if (!$include_path) {
        unset($job['image_path']);
    }

    return $job;
}

/**
 * Serve a completed screenshot image to authenticated clients.
 */
function openmira_ajax_get_screenshot_image(): void
{
    if (!current_user_can('manage_options')) {
        status_header(403);
        exit();
    }

    $job_param = $_GET['job_id'] ?? '';
    $job_id = sanitize_key(is_string($job_param) ? $job_param : '');
    if ($job_id === '') {
        status_header(400);
        exit();
    }

    check_ajax_referer('openmira_screenshot_image_' . $job_id);

    $job = openmira_get_screenshot_job($job_id);
    if (!is_array($job) || ($job['status'] ?? '') !== 'complete') {
        status_header(404);
        exit();
    }

    $image_path = is_string($job['image_path'] ?? null) ? $job['image_path'] : '';
    if ($image_path === '' || !is_file($image_path) || !is_readable($image_path)) {
        status_header(404);
        exit();
    }

    $mime_type = is_string($job['mime_type'] ?? null) ? $job['mime_type'] : 'image/png';
    if (!in_array($mime_type, ['image/png', 'image/jpeg'], strict: true)) {
        status_header(415);
        exit();
    }

    nocache_headers();
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . (string) filesize($image_path));
    readfile($image_path);
    exit();
}

/**
 * Normalize a same-site screenshot URL.
 *
 * @return string|WP_Error
 */
function openmira_normalize_screenshot_url(string $url): string|WP_Error
{
    $url = trim($url);
    if ($url === '') {
        return new WP_Error('missing_url', 'Provide a URL to screenshot.');
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = home_url($url);
    }
    $url = esc_url_raw($url);
    if ($url === '') {
        return new WP_Error('invalid_url', 'Screenshot URL is invalid.');
    }

    // @mago-expect analysis:mixed-assignment
    $target_host = wp_parse_url($url, component: PHP_URL_HOST);
    // @mago-expect analysis:mixed-assignment
    $site_host = wp_parse_url(home_url('/'), component: PHP_URL_HOST);
    if (!is_string($target_host) || !is_string($site_host) || strcasecmp($target_host, $site_host) !== 0) {
        if (apply_filters('openmira_allow_external_screenshot_urls', false, $url) !== true) {
            return new WP_Error('external_url_not_allowed', 'Screenshot URLs must target the current WordPress site.');
        }
    }

    return $url;
}

/**
 * Clamp an integer.
 */
function openmira_clamp_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

/**
 * Strip optional data URL prefix from base64 image payloads.
 */
function openmira_normalize_base64_image(string $image_base64): string
{
    $image_base64 = trim($image_base64);
    $comma = strpos($image_base64, ',');
    if (str_starts_with($image_base64, 'data:image/') && $comma !== false) {
        $image_base64 = substr($image_base64, $comma + 1);
    }

    return preg_replace('/\s+/', '', $image_base64) ?? '';
}

/**
 * Return whether image bytes match a MIME type.
 */
function openmira_image_bytes_match_mime(string $bytes, string $mime_type): bool
{
    if ($mime_type === 'image/png') {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
    }

    return $mime_type === 'image/jpeg' && str_starts_with($bytes, "\xff\xd8\xff");
}

/**
 * Return a writable screenshot image path.
 *
 * @return string|WP_Error
 */
function openmira_get_screenshot_image_path(string $job_id, string $mime_type): string|WP_Error
{
    $directory = trailingslashit(WP_CONTENT_DIR) . 'openmira-screenshots';
    if (!wp_mkdir_p($directory)) {
        return new WP_Error('screenshot_directory_failed', 'Could not create screenshot directory.');
    }

    $extension = $mime_type === 'image/jpeg' ? 'jpg' : 'png';
    return trailingslashit($directory) . sanitize_key($job_id) . '.' . $extension;
}
