<?php

declare(strict_types=1);

/**
 * Admin UI for Open Mira audit log and file backups.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle audit admin actions.
 */
function openmira_handle_audit_admin_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = is_string($_POST['openmira_audit_action'] ?? null) ? $_POST['openmira_audit_action'] : '';
    check_admin_referer(action: 'openmira_audit_action', query_arg: '_openmira_audit_nonce');

    $result = match ($action) {
        'clear_audit' => openmira_handle_clear_audit_action(),
        'save_safety' => openmira_handle_save_safety_action(),
        default => '',
    };
    if ($result === '') {
        return;
    }

    wp_safe_redirect(add_query_arg([
        'page' => 'openmira-audit',
        'openmira_audit_result' => $result,
    ], admin_url('admin.php')));
    exit();
}

/**
 * Clear audit action.
 */
function openmira_handle_clear_audit_action(): string
{
    openmira_clear_audit_log();

    return 'cleared';
}

/**
 * Save safety settings action.
 */
function openmira_handle_save_safety_action(): string
{
    openmira_set_plan_act_required(($_POST['plan_act_required'] ?? '') === '1');

    return 'safety_saved';
}

/**
 * Render the audit page.
 */
function openmira_render_audit_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $events = array_slice(openmira_get_audit_log(), offset: 0, length: 100);
    $backups = array_slice(openmira_list_file_backups(), offset: 0, length: 100);
    $result = is_string($_GET['openmira_audit_result'] ?? null) ? $_GET['openmira_audit_result'] : '';

    openmira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Audit Log', domain: 'open-mira'); ?></h1>
        <p><?php esc_html_e(
            'Review recent Open Mira file operations, diffs, backup IDs, and restore points.',
            domain: 'open-mira',
        ); ?></p>
        <?php openmira_render_audit_notice($result); ?>
        <?php openmira_render_safety_settings(); ?>
        <?php openmira_render_audit_table($events); ?>
        <?php openmira_render_file_backup_table($backups); ?>
    </div>
    <?php
}

/**
 * Render action notices.
 */
function openmira_render_audit_notice(string $result): void
{
    $message = match ($result) {
        'cleared' => __('Audit log cleared.', domain: 'open-mira'),
        'safety_saved' => __('Safety settings saved.', domain: 'open-mira'),
        default => '',
    };
    if ($message === '') {
        return;
    }
    ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($message); ?></p>
    </div>
    <?php
}

/**
 * Render safety settings.
 */
function openmira_render_safety_settings(): void
{ ?>
    <h2><?php esc_html_e('Safety Mode', domain: 'open-mira'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=openmira-audit')); ?>">
        <?php wp_nonce_field(action: 'openmira_audit_action', name: '_openmira_audit_nonce'); ?>
        <input type="hidden" name="openmira_audit_action" value="save_safety">
        <label>
            <input
                type="checkbox"
                name="plan_act_required"
                value="1"
                <?php checked(openmira_is_plan_act_required()); ?>
            >
            <?php esc_html_e(
                'Require temporary Act mode before destructive MCP abilities can run.',
                domain: 'open-mira',
            ); ?>
        </label>
        <p class="description"><?php esc_html_e(
            'When enabled, agents must call openmira/set-safety-mode with mode=act before writes, deletes, restores, PHP execution, and builder writes.',
            domain: 'open-mira',
        ); ?></p>
        <?php submit_button(text: __('Save Safety Settings', domain: 'open-mira')); ?>
    </form>
    <?php }

/**
 * Render audit event table.
 *
 * @param list<array<array-key, mixed>> $events
 */
function openmira_render_audit_table(array $events): void
{ ?>
    <h2><?php esc_html_e('Recent Events', domain: 'open-mira'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=openmira-audit')); ?>">
        <?php wp_nonce_field(action: 'openmira_audit_action', name: '_openmira_audit_nonce'); ?>
        <input type="hidden" name="openmira_audit_action" value="clear_audit">
        <?php submit_button(
            text: __('Clear Audit Log', domain: 'open-mira'),
            type: 'delete',
            name: 'submit',
            wrap: false,
            other_attributes: [
                'onclick' => "return confirm('" . esc_js(__('Clear all audit events?', domain: 'open-mira')) . "');",
            ],
        ); ?>
    </form>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Time', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Ability', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Target', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Status', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Diff', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Backup', domain: 'open-mira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($events === []) { ?>
                <tr><td colspan="6"><?php esc_html_e('No audit events yet.', domain: 'open-mira'); ?></td></tr>
            <?php } ?>
            <?php foreach ($events as $event) { ?>
                <tr>
                    <td><?php echo esc_html(openmira_audit_format_time((string) ($event['created_at'] ?? ''))); ?></td>
                    <td><code><?php echo esc_html((string) ($event['ability'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($event['target_path'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($event['status'] ?? '')); ?></td>
                    <td><?php openmira_render_audit_diff_cell($event); ?></td>
                    <td><code><?php echo esc_html((string) ($event['backup_id'] ?? '')); ?></code></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php }

/**
 * Render a compact diff summary with expandable full diff.
 *
 * @param array<array-key, mixed> $event
 */
function openmira_render_audit_diff_cell(array $event): void
{
    $summary = openmira_audit_event_diff_summary($event);
    $diff = openmira_audit_event_diff($event);
    if ($diff === '') {
        echo esc_html($summary);
        return;
    }
    ?>
    <details class="openmira-audit-diff">
        <summary><?php echo esc_html($summary !== '' ? $summary : __('Show diff', domain: 'open-mira')); ?></summary>
        <pre style="max-height:360px; overflow:auto; white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; padding:8px; margin:8px 0 0; font-size:12px;"><?php echo
            esc_html($diff)
        ; ?></pre>
    </details>
    <?php
}

/**
 * Return the stored full diff for an audit event.
 *
 * @param array<array-key, mixed> $event
 */
function openmira_audit_event_diff(array $event): string
{
    return array_key_exists('diff', $event) && is_string($event['diff']) ? $event['diff'] : '';
}

/**
 * Return a readable diff summary for an audit event.
 *
 * @param array<array-key, mixed> $event
 */
function openmira_audit_event_diff_summary(array $event): string
{
    return array_key_exists('diff_summary', $event) && is_string($event['diff_summary']) ? $event['diff_summary'] : '';
}

/**
 * Render backup table.
 *
 * @param list<array<array-key, mixed>> $backups
 */
function openmira_render_file_backup_table(array $backups): void
{ ?>
    <h2><?php esc_html_e('File Backups', domain: 'open-mira'); ?></h2>
    <p><?php esc_html_e(
        'Restore backups through the openmira/restore-file-backup MCP ability. The UI is intentionally read-only for now.',
        domain: 'open-mira',
    ); ?></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Created', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Target', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Operation', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Backup ID', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Size', domain: 'open-mira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($backups === []) { ?>
                <tr><td colspan="5"><?php esc_html_e('No file backups yet.', domain: 'open-mira'); ?></td></tr>
            <?php } ?>
            <?php foreach ($backups as $backup) { ?>
                <tr>
                    <td><?php echo esc_html(openmira_audit_format_time((string) ($backup['created_at'] ?? ''))); ?></td>
                    <td><code><?php echo esc_html((string) ($backup['target_path'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($backup['operation'] ?? '')); ?></td>
                    <td><code><?php echo esc_html((string) ($backup['id'] ?? '')); ?></code></td>
                    <td><?php echo esc_html(openmira_audit_format_size((int) ($backup['size'] ?? 0))); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php }

/**
 * Format a stored UTC ISO timestamp for admin display.
 */
function openmira_audit_format_time(string $timestamp): string
{
    if ($timestamp === '') {
        return '';
    }

    $unix = strtotime($timestamp);
    if ($unix === false) {
        return $timestamp;
    }

    $formatted = wp_date(openmira_get_datetime_format(), $unix);

    return is_string($formatted) ? $formatted : $timestamp;
}

/**
 * Format byte size for admin display.
 */
function openmira_audit_format_size(int $size): string
{
    $formatted = size_format($size);

    return is_string($formatted) ? $formatted : (string) $size;
}
