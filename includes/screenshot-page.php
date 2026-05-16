<?php

declare(strict_types=1);

/**
 * Browser-assisted screenshot runner page.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('wp_ajax_openmira_get_screenshot_image', callback: 'openmira_handle_screenshot_image_ajax');
add_action('rest_api_init', callback: 'openmira_register_screenshot_runner_routes');
add_action('admin_notices', callback: 'openmira_render_screenshot_runner_admin_notice');

/**
 * Register REST routes used by the persistent screenshot runner tab.
 */
function openmira_register_screenshot_runner_routes(): void
{
    register_rest_route(route_namespace: 'openmira/v1', route: '/screenshot-runner/jobs', args: [
        'methods' => 'GET',
        'callback' => 'openmira_rest_screenshot_runner_jobs',
        'permission_callback' => 'openmira_screenshot_runner_permission',
    ]);

    register_rest_route(route_namespace: 'openmira/v1', route: '/screenshot-runner/heartbeat', args: [
        'methods' => 'POST',
        'callback' => 'openmira_rest_screenshot_runner_heartbeat',
        'permission_callback' => 'openmira_screenshot_runner_permission',
    ]);

    register_rest_route(route_namespace: 'openmira/v1', route: '/screenshot-runner/complete', args: [
        'methods' => 'POST',
        'callback' => 'openmira_rest_screenshot_runner_complete',
        'permission_callback' => 'openmira_screenshot_runner_permission',
    ]);
}

/**
 * Permission callback for screenshot runner REST routes.
 */
function openmira_screenshot_runner_permission(): bool
{
    return current_user_can('manage_options');
}

/**
 * Render the screenshot runner page.
 */
function openmira_render_screenshot_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    openmira_require_screenshot_helpers();

    $job_param = $_GET['job'] ?? '';
    $job_id = sanitize_key(is_string($job_param) ? $job_param : '');
    $job = $job_id !== '' ? openmira_get_screenshot_job($job_id) : null;
    $single_job_missing = $job_id !== '' && !is_array($job);

    openmira_mark_screenshot_runner_seen();
    openmira_render_admin_header();

    $config = [
        'jobsUrl' => rest_url('openmira/v1/screenshot-runner/jobs'),
        'heartbeatUrl' => rest_url('openmira/v1/screenshot-runner/heartbeat'),
        'completeUrl' => rest_url('openmira/v1/screenshot-runner/complete'),
        'nonce' => wp_create_nonce('wp_rest'),
        'singleJobId' => $job_id,
        'pollMs' => 1500,
        'settleMs' => 700,
        'captureTimeoutMs' => 30_000,
        'maxCaptureHeight' => 8000,
    ];
    $plugin_url = (string) OPENMIRA_PLUGIN_URL;
    $version = OPENMIRA_VERSION;
    ?>
    <div class="wrap openmira-screenshot-runner">
        <h1><?php esc_html_e('Screenshot Runner', domain: 'open-mira'); ?></h1>
        <?php if ($single_job_missing): ?>
            <div class="notice notice-error openmira-keep">
                <p><?php esc_html_e('Screenshot job not found or expired.', domain: 'open-mira'); ?></p>
            </div>
        <?php endif; ?>
        <div class="notice notice-info openmira-keep">
            <p>
                <?php esc_html_e(
                    'Leave this tab open during a dev session. Pending screenshot jobs created by an agent will auto-capture sequentially without per-job clicks.',
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>

        <div class="openmira-runner-toolbar">
            <button type="button" class="button" id="openmira-runner-refresh"><?php esc_html_e(
                'Refresh queue',
                domain: 'open-mira',
            ); ?></button>
            <label><input type="checkbox" id="openmira-runner-pause"> <?php esc_html_e(
                'Pause auto-capture',
                domain: 'open-mira',
            ); ?></label>
            <span id="openmira-runner-status" class="openmira-runner-status"><?php esc_html_e(
                'Starting…',
                domain: 'open-mira',
            ); ?></span>
        </div>

        <div class="openmira-runner-grid">
            <section class="openmira-runner-panel">
                <h2><?php esc_html_e('Queue', domain: 'open-mira'); ?></h2>
                <p class="description" id="openmira-runner-counts"></p>
                <div id="openmira-runner-jobs" class="openmira-runner-jobs"></div>
            </section>
            <section class="openmira-runner-panel">
                <h2><?php esc_html_e('Active capture', domain: 'open-mira'); ?></h2>
                <div id="openmira-runner-active" class="openmira-runner-active"><?php esc_html_e(
                    'No active capture.',
                    domain: 'open-mira',
                ); ?></div>
                <div class="openmira-runner-frame-wrap" id="openmira-runner-frame-wrap" hidden>
                    <iframe id="openmira-runner-frame" title="<?php esc_attr_e(
                        'Open Mira screenshot target',
                        domain: 'open-mira',
                    ); ?>"></iframe>
                </div>
            </section>
        </div>
    </div>
    <style>
        .openmira-runner-toolbar { align-items: center; display: flex; flex-wrap: wrap; gap: 14px; margin: 16px 0; }
        .openmira-runner-status { color: #646970; }
        .openmira-runner-grid { display: grid; gap: 18px; grid-template-columns: minmax(320px, .8fr) minmax(420px, 1.2fr); max-width: 1320px; }
        .openmira-runner-panel { background: #fff; border: 1px solid #dcdcde; box-sizing: border-box; padding: 16px; }
        .openmira-runner-panel h2 { margin-top: 0; }
        .openmira-runner-jobs { display: grid; gap: 10px; }
        .openmira-runner-job { border: 1px solid #dcdcde; border-left-width: 4px; padding: 10px 12px; }
        .openmira-runner-job[data-status="pending"] { border-left-color: #dba617; }
        .openmira-runner-job[data-status="complete"] { border-left-color: #008a20; }
        .openmira-runner-job[data-status="failed"] { border-left-color: #d63638; }
        .openmira-runner-job-title { align-items: center; display: flex; gap: 8px; justify-content: space-between; }
        .openmira-runner-job-title code { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .openmira-runner-job-meta { color: #646970; font-size: 12px; margin: 6px 0; word-break: break-all; }
        .openmira-runner-thumb { border: 1px solid #dcdcde; display: block; height: auto; margin-top: 8px; max-width: 220px; }
        .openmira-runner-active { color: #646970; margin-bottom: 10px; }
        .openmira-runner-frame-wrap { border: 1px solid #dcdcde; max-height: 72vh; overflow: auto; padding: 8px; }
        #openmira-runner-frame { background: #fff; border: 0; display: block; }
        @media (max-width: 960px) { .openmira-runner-grid { grid-template-columns: 1fr; } }
    </style>
    <script src="<?php echo esc_url($plugin_url . 'assets/vendor/html-to-image.js'); ?>?ver=1.11.11"></script>
    <script>
        window.openMiraScreenshotRunner = <?php echo wp_json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="<?php echo esc_url($plugin_url . 'assets/openmira-screenshot-runner.js'); ?>?ver=<?php echo
        esc_attr($version)
    ; ?>"></script>
    <?php
}

/**
 * REST callback returning queue jobs for the current user.
 */
function openmira_rest_screenshot_runner_jobs(WP_REST_Request $request): WP_REST_Response
{
    openmira_require_screenshot_helpers();
    openmira_mark_screenshot_runner_seen();

    $job_id = sanitize_key((string) ($request->get_param('job_id') ?? ''));
    $jobs = openmira_get_screenshot_runner_jobs($job_id);

    return rest_ensure_response([
        'jobs' => $jobs,
        'pending_count' => openmira_count_pending_screenshot_jobs_for_user(),
        'runner_active' => true,
        'server_time' => time(),
    ]);
}

/**
 * REST callback refreshing runner activity state.
 */
function openmira_rest_screenshot_runner_heartbeat(): WP_REST_Response
{
    openmira_mark_screenshot_runner_seen();

    return rest_ensure_response([
        'runner_active' => true,
        'server_time' => time(),
    ]);
}

/**
 * REST callback completing one screenshot job from the runner browser tab.
 */
function openmira_rest_screenshot_runner_complete(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    openmira_require_screenshot_helpers();

    $params = $request->get_json_params();
    $job_id = sanitize_key((string) ($params['job_id'] ?? ''));
    $job = $job_id !== '' ? openmira_get_screenshot_job($job_id) : null;
    if (!is_array($job)) {
        return new WP_Error('screenshot_job_not_found', 'Screenshot job not found.', ['status' => 404]);
    }
    if (!openmira_screenshot_job_belongs_to_current_user($job)) {
        return new WP_Error('screenshot_job_forbidden', 'This screenshot job belongs to a different user.', [
            'status' => 403,
        ]);
    }

    $result = openmira_complete_screenshot_url_job([
        'job_id' => $job_id,
        'image_base64' => (string) ($params['image_base64'] ?? ''),
        'mime_type' => (string) ($params['mime_type'] ?? 'image/png'),
        'error' => (string) ($params['error'] ?? ''),
    ]);

    if (is_wp_error($result)) {
        return $result;
    }

    return rest_ensure_response($result);
}

/**
 * Return screenshot jobs visible to the current runner user.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_get_screenshot_runner_jobs(string $only_job_id = ''): array
{
    openmira_require_screenshot_helpers();

    $jobs = [];
    foreach (openmira_get_screenshot_jobs() as $job_id => $job) {
        if ($only_job_id !== '' && $job_id !== $only_job_id) {
            continue;
        }
        if (!openmira_screenshot_job_belongs_to_current_user($job)) {
            continue;
        }

        $public = openmira_public_screenshot_job($job, include_path: false);
        $public['runner_url'] = openmira_get_screenshot_job_url($job_id);
        $public['resource_uri'] = openmira_get_screenshot_job_resource_uri($job_id);
        if (($job['status'] ?? '') === 'complete') {
            $public['image_url'] = openmira_get_screenshot_image_url($job_id);
        }
        $jobs[] = $public;
    }

    usort($jobs, static fn(array $a, array $b): int => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));

    return $jobs;
}

/**
 * Return whether a screenshot job belongs to the current user.
 *
 * @param array<array-key, mixed> $job
 */
function openmira_screenshot_job_belongs_to_current_user(array $job): bool
{
    $created_by = (int) ($job['created_by'] ?? 0);
    return $created_by === 0 || $created_by === get_current_user_id();
}

/**
 * Count pending jobs visible to the current user.
 */
function openmira_count_pending_screenshot_jobs_for_user(): int
{
    $count = 0;
    foreach (openmira_get_raw_screenshot_jobs() as $job) {
        if (($job['status'] ?? '') !== 'pending') {
            continue;
        }
        if (openmira_screenshot_job_belongs_to_current_user($job)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Return stored screenshot jobs without requiring ability registration helpers.
 *
 * @return array<string, array<array-key, mixed>>
 */
function openmira_get_raw_screenshot_jobs(): array
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
 * Persist that the current user's runner tab is active.
 */
function openmira_mark_screenshot_runner_seen(): void
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    set_transient(transient: openmira_screenshot_runner_transient_key($user_id), value: time(), expiration: 30);
}

/**
 * Return whether the current user's runner tab appears active.
 */
function openmira_is_screenshot_runner_active_for_current_user(): bool
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return false;
    }

    // @mago-expect analysis:mixed-assignment
    $last_seen = get_transient(openmira_screenshot_runner_transient_key($user_id));
    return is_numeric($last_seen) && (time() - (int) $last_seen) <= 12;
}

/**
 * Return the runner heartbeat transient key for a user.
 */
function openmira_screenshot_runner_transient_key(int $user_id): string
{
    return 'openmira_screenshot_runner_seen_' . $user_id;
}

/**
 * Render a notice when pending screenshot jobs are waiting but no queue tab is active.
 */
function openmira_render_screenshot_runner_admin_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? '';
    if (!is_string($page) || !str_starts_with($page, 'openmira') || $page === 'openmira-screenshot-tools') {
        return;
    }

    $pending_count = openmira_count_pending_screenshot_jobs_for_user();
    if ($pending_count <= 0 || openmira_is_screenshot_runner_active_for_current_user()) {
        return;
    }

    $url = admin_url('admin.php?page=openmira-screenshot-tools');
    ?>
    <div class="notice notice-warning openmira-keep">
        <p>
            <strong><?php esc_html_e('Screenshot Runner inactive.', domain: 'open-mira'); ?></strong>
            <?php echo
                esc_html(sprintf(
                    _n(
                        single: '%d pending screenshot job is waiting.',
                        plural: '%d pending screenshot jobs are waiting.',
                        number: $pending_count,
                        domain: 'open-mira',
                    ),
                    $pending_count,
                ))
            ; ?>
            <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Open Runner →', domain: 'open-mira'); ?></a>
        </p>
    </div>
    <?php
}

/**
 * Ensure screenshot helper functions are loaded.
 */
function openmira_require_screenshot_helpers(): void
{
    if (!function_exists('openmira_get_screenshot_job')) {
        require_once __DIR__ . '/abilities/screenshot-url.php';
    }
}

/**
 * Load screenshot helpers before serving the protected image endpoint.
 */
function openmira_handle_screenshot_image_ajax(): void
{
    openmira_require_screenshot_helpers();
    openmira_ajax_get_screenshot_image();
}
