<?php

declare(strict_types=1);

/**
 * Admin UI for persistent Open Mira project memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle memory admin actions.
 */
function novamira_handle_memory_admin_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? '';
    if ($page !== 'novamira-memory') {
        return;
    }

    if (array_key_exists('novamira_export_memory', $_GET)) {
        novamira_export_memory_entries();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = is_string($_POST['novamira_memory_action'] ?? null) ? $_POST['novamira_memory_action'] : '';
    check_admin_referer(action: 'novamira_memory_action', query_arg: '_novamira_memory_nonce');

    $redirect_args = ['page' => 'novamira-memory'];
    $result = match ($action) {
        'save' => novamira_handle_memory_save_action(),
        'delete' => novamira_handle_memory_delete_action(),
        'clear' => novamira_handle_memory_clear_action(),
        default => new WP_Error('invalid_memory_action', 'Invalid memory action.'),
    };

    if (is_wp_error($result)) {
        $redirect_args['novamira_memory_error'] = rawurlencode($result->get_error_message());
    }
    if (!is_wp_error($result)) {
        $redirect_args['novamira_memory_result'] = $result;
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit();
}

/**
 * Save a memory entry from the admin UI.
 *
 * @return string|WP_Error
 */
function novamira_handle_memory_save_action(): string|WP_Error
{
    $key = sanitize_text_field(novamira_memory_request_string($_POST['memory_key'] ?? null));
    $value = novamira_memory_request_string($_POST['memory_value'] ?? null);

    $result = novamira_write_memory([
        'key' => $key,
        'value' => $value,
    ]);
    if (is_wp_error($result)) {
        return $result;
    }

    return ($result['created'] ?? false) === true ? 'created' : 'updated';
}

/**
 * Delete a memory entry from the admin UI.
 *
 * @return string|WP_Error
 */
function novamira_handle_memory_delete_action(): string|WP_Error
{
    $key = sanitize_text_field(novamira_memory_request_string($_POST['memory_key'] ?? null));
    $result = novamira_delete_memory(['key' => $key]);
    if (is_wp_error($result)) {
        return $result;
    }

    return ($result['deleted'] ?? false) === true ? 'deleted' : 'not_found';
}

/**
 * Clear all memory entries from the admin UI.
 */
function novamira_handle_memory_clear_action(): string
{
    novamira_update_memory_entries([]);

    return 'cleared';
}

/**
 * Export memory entries as JSON.
 */
function novamira_export_memory_entries(): void
{
    check_admin_referer(action: 'novamira_export_memory');

    $payload = [
        'exported_at' => current_time(type: 'mysql', gmt: true),
        'entries' => novamira_get_memory_entries(),
    ];
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        wp_die(esc_html__('Could not encode memory export.', domain: 'novamira'));
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="open-mira-memory.json"');
    echo $json;
    exit();
}

/**
 * Render the memory admin page.
 */
function novamira_render_memory_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $entries = novamira_get_memory_entries();
    ksort($entries);
    $editing_key = sanitize_text_field(novamira_memory_request_string($_GET['edit'] ?? null));
    $editing_entry = $editing_key !== '' && is_array($entries[$editing_key] ?? null) ? $entries[$editing_key] : null;
    $result = is_string($_GET['novamira_memory_result'] ?? null) ? $_GET['novamira_memory_result'] : '';
    $error = is_string($_GET['novamira_memory_error'] ?? null)
        ? sanitize_text_field(wp_unslash($_GET['novamira_memory_error']))
        : '';

    novamira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Memory', domain: 'novamira'); ?></h1>
        <p><?php esc_html_e(
            'Review and manage persistent project facts stored by Open Mira MCP abilities.',
            domain: 'novamira',
        ); ?></p>
        <?php novamira_render_memory_notice($result, $error); ?>
        <?php novamira_render_memory_editor($editing_key, $editing_entry); ?>
        <?php novamira_render_memory_table($entries); ?>
    </div>
    <?php
}

/**
 * Read a scalar request value as an unslashed string.
 */
function novamira_memory_request_string(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    return wp_unslash($value);
}

/**
 * Render memory action notices.
 */
function novamira_render_memory_notice(string $result, string $error): void
{
    if ($error !== '') {
        ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php

        return;
    }

    $message = match ($result) {
        'created' => __('Memory entry created.', domain: 'novamira'),
        'updated' => __('Memory entry updated.', domain: 'novamira'),
        'deleted' => __('Memory entry deleted.', domain: 'novamira'),
        'not_found' => __('Memory entry was already absent.', domain: 'novamira'),
        'cleared' => __('All memory entries cleared.', domain: 'novamira'),
        default => '',
    };
    if ($message === '') {
        return;
    }
    ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php
}

/**
 * Render the create/edit form.
 *
 * @param array<array-key, mixed>|null $editing_entry
 */
function novamira_render_memory_editor(string $editing_key, ?array $editing_entry): void
{
    $value = is_array($editing_entry) ? (string) ($editing_entry['value'] ?? '') : '';
    ?>
    <h2><?php echo
        $editing_key !== ''
            ? esc_html__('Edit Memory Entry', domain: 'novamira')
            : esc_html__('Add Memory Entry', domain: 'novamira')
    ; ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=novamira-memory')); ?>">
        <?php wp_nonce_field(action: 'novamira_memory_action', name: '_novamira_memory_nonce'); ?>
        <input type="hidden" name="novamira_memory_action" value="save">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="novamira-memory-key"><?php esc_html_e(
                    'Key',
                    domain: 'novamira',
                ); ?></label></th>
                <td>
                    <input
                        id="novamira-memory-key"
                        class="regular-text"
                        name="memory_key"
                        pattern="^[a-z0-9][a-z0-9._-]{0,79}$"
                        required
                        value="<?php echo esc_attr($editing_key); ?>"
                    >
                    <p class="description"><?php esc_html_e(
                        'Use lowercase letters, numbers, dots, underscores, and hyphens. Maximum 80 characters.',
                        domain: 'novamira',
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="novamira-memory-value"><?php esc_html_e(
                    'Value',
                    domain: 'novamira',
                ); ?></label></th>
                <td>
                    <textarea id="novamira-memory-value" class="large-text code" rows="8" name="memory_value" required><?php echo
                        esc_textarea($value)
                    ; ?></textarea>
                    <p class="description"><?php esc_html_e(
                        'Store durable project facts only, not transient scratch notes.',
                        domain: 'novamira',
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(
            $editing_key !== '' ? __('Update Memory', domain: 'novamira') : __('Add Memory', domain: 'novamira'),
        ); ?>
    </form>
    <?php
}

/**
 * Render the memory entries table.
 *
 * @param array<string, array<array-key, mixed>> $entries
 */
function novamira_render_memory_table(array $entries): void
{
    $export_url = wp_nonce_url(
        admin_url('admin.php?page=novamira-memory&novamira_export_memory=1'),
        action: 'novamira_export_memory',
    );
    ?>
    <hr>
    <h2><?php esc_html_e('Stored Entries', domain: 'novamira'); ?></h2>
    <p>
        <a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e(
            'Export JSON',
            domain: 'novamira',
        ); ?></a>
    </p>
    <?php novamira_render_memory_clear_form($entries); ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:220px;"><?php esc_html_e('Key', domain: 'novamira'); ?></th>
                <th><?php esc_html_e('Value', domain: 'novamira'); ?></th>
                <th style="width:180px;"><?php esc_html_e('Updated', domain: 'novamira'); ?></th>
                <th style="width:120px;"><?php esc_html_e('Updated By', domain: 'novamira'); ?></th>
                <th style="width:170px;"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($entries === []): ?>
                <tr><td colspan="5"><?php esc_html_e('No memory entries stored.', domain: 'novamira'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $key => $entry): ?>
                <?php novamira_render_memory_row($key, $entry); ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Render the clear-all form.
 *
 * @param array<string, array<array-key, mixed>> $entries
 */
function novamira_render_memory_clear_form(array $entries): void
{
    if ($entries === []) {
        return;
    }
    ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=novamira-memory'))
    ; ?>" style="margin: 8px 0 16px;">
        <?php wp_nonce_field(action: 'novamira_memory_action', name: '_novamira_memory_nonce'); ?>
        <input type="hidden" name="novamira_memory_action" value="clear">
        <button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo
            esc_js(__('Delete all memory entries?', domain: 'novamira'))
        ; ?>');">
            <?php esc_html_e('Clear All Memory', domain: 'novamira'); ?>
        </button>
    </form>
    <?php
}

/**
 * Render one memory row.
 *
 * @param array<array-key, mixed> $entry
 */
function novamira_render_memory_row(string $key, array $entry): void
{
    $edit_url = add_query_arg(['page' => 'novamira-memory', 'edit' => $key], admin_url('admin.php'));
    $user_id = (int) ($entry['updated_by'] ?? 0);
    $user = $user_id > 0 ? get_userdata($user_id) : false;
    $updated_by = '—';
    if ($user instanceof WP_User) {
        $updated_by = $user->display_name;
    }
    if (!$user instanceof WP_User && $user_id > 0) {
        $updated_by = (string) $user_id;
    }
    ?>
    <tr>
        <td><code><?php echo esc_html($key); ?></code></td>
        <td><pre style="white-space:pre-wrap;margin:0;"><?php echo
            esc_html((string) ($entry['value'] ?? ''))
        ; ?></pre></td>
        <td><?php echo esc_html((string) ($entry['updated_at'] ?? '')); ?></td>
        <td><?php echo esc_html($updated_by); ?></td>
        <td>
            <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                'Edit',
                domain: 'novamira',
            ); ?></a>
            <?php novamira_render_memory_delete_form($key); ?>
        </td>
    </tr>
    <?php
}

/**
 * Render a delete form for one memory key.
 */
function novamira_render_memory_delete_form(string $key): void
{ ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=novamira-memory'))
    ; ?>" style="display:inline;">
        <?php wp_nonce_field(action: 'novamira_memory_action', name: '_novamira_memory_nonce'); ?>
        <input type="hidden" name="novamira_memory_action" value="delete">
        <input type="hidden" name="memory_key" value="<?php echo esc_attr($key); ?>">
        <button type="submit" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo
            esc_js(__('Delete this memory entry?', domain: 'novamira'))
        ; ?>');">
            <?php esc_html_e('Delete', domain: 'novamira'); ?>
        </button>
    </form>
    <?php }
