<?php

declare(strict_types=1);

/**
 * Browser-assisted screenshot runner page.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('wp_ajax_openmira_get_screenshot_image', callback: 'openmira_handle_screenshot_image_ajax');

/**
 * Render the screenshot runner page.
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_render_screenshot_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $job_param = $_GET['job'] ?? '';
    $job_id = sanitize_key(is_string($job_param) ? $job_param : '');
    if ($job_id !== '' && !function_exists('openmira_get_screenshot_job')) {
        require_once __DIR__ . '/abilities/screenshot-url.php';
    }
    $job = $job_id !== '' && function_exists('openmira_get_screenshot_job')
        ? openmira_get_screenshot_job($job_id)
        : null;

    openmira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Screenshot Runner', domain: 'open-mira'); ?></h1>
        <?php if (!is_array($job)): ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('Screenshot job not found or expired.', domain: 'open-mira'); ?></p>
            </div>
        <?php else: ?>
            <div class="notice notice-info">
                <p>
                    <?php esc_html_e(
                        'Open this target in an authenticated browser automation client, capture the requested viewport, then complete the job with the PNG bytes.',
                        domain: 'open-mira',
                    ); ?>
                </p>
            </div>
            <table class="widefat striped" style="max-width: 980px;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Job ID', domain: 'open-mira'); ?></th>
                        <td><code><?php echo esc_html($job_id); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Target URL', domain: 'open-mira'); ?></th>
                        <td>
                            <a href="<?php echo
                                esc_url((string) ($job['target_url'] ?? ''))
                            ; ?>" target="_blank" rel="noreferrer">
                                <?php echo esc_html((string) ($job['target_url'] ?? '')); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Viewport', domain: 'open-mira'); ?></th>
                        <td>
                            <?php echo esc_html((string) ($job['viewport_width'] ?? 0)); ?>
                            ×
                            <?php echo esc_html((string) ($job['viewport_height'] ?? 0)); ?>
                            <?php echo
                                ($job['full_page'] ?? false) === true
                                    ? esc_html__('full page', domain: 'open-mira')
                                    : ''
                            ; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', domain: 'open-mira'); ?></th>
                        <td><code><?php echo esc_html((string) ($job['status'] ?? 'pending')); ?></code></td>
                    </tr>
                </tbody>
            </table>
            <p>
                <a class="button button-primary" href="<?php echo
                    esc_url((string) ($job['target_url'] ?? ''))
                ; ?>" target="_blank" rel="noreferrer">
                    <?php esc_html_e('Open Target', domain: 'open-mira'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Load screenshot helpers before serving the protected image endpoint.
 */
function openmira_handle_screenshot_image_ajax(): void
{
    if (!function_exists('openmira_ajax_get_screenshot_image')) {
        require_once __DIR__ . '/abilities/screenshot-url.php';
    }

    openmira_ajax_get_screenshot_image();
}
