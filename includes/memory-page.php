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
function openmira_handle_memory_admin_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? '';
    if ($page !== 'openmira-memory') {
        return;
    }

    if (array_key_exists('openmira_export_memory', $_GET)) {
        openmira_export_memory_entries();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = is_string($_POST['openmira_memory_action'] ?? null) ? $_POST['openmira_memory_action'] : '';
    check_admin_referer(action: 'openmira_memory_action', query_arg: '_openmira_memory_nonce');

    $redirect_args = ['page' => 'openmira-memory'];
    $result = match ($action) {
        'save' => openmira_handle_memory_save_action(),
        'delete' => openmira_handle_memory_delete_action(),
        'clear' => openmira_handle_memory_clear_action(),
        default => new WP_Error('invalid_memory_action', 'Invalid memory action.'),
    };

    if (is_wp_error($result)) {
        $redirect_args['openmira_memory_error'] = rawurlencode($result->get_error_message());
    }
    if (!is_wp_error($result)) {
        $redirect_args['openmira_memory_result'] = $result;
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit();
}

/**
 * Save a memory entry from the admin UI.
 *
 * @return string|WP_Error
 */
function openmira_handle_memory_save_action(): string|WP_Error
{
    $key = sanitize_text_field(openmira_memory_request_string($_POST['memory_key'] ?? null));
    $value = openmira_memory_request_string($_POST['memory_value'] ?? null);

    $result = openmira_write_memory([
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
function openmira_handle_memory_delete_action(): string|WP_Error
{
    $key = sanitize_text_field(openmira_memory_request_string($_POST['memory_key'] ?? null));
    $result = openmira_delete_memory(['key' => $key]);
    if (is_wp_error($result)) {
        return $result;
    }

    return ($result['deleted'] ?? false) === true ? 'deleted' : 'not_found';
}

/**
 * Clear all memory entries from the admin UI.
 */
function openmira_handle_memory_clear_action(): string
{
    openmira_update_memory_entries([]);

    return 'cleared';
}

/**
 * Export memory entries as JSON.
 */
function openmira_export_memory_entries(): void
{
    check_admin_referer(action: 'openmira_export_memory');

    $payload = [
        'exported_at' => current_time(type: 'mysql', gmt: true),
        'entries' => openmira_get_memory_entries(),
    ];
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        wp_die(esc_html__('Could not encode memory export.', domain: 'open-mira'));
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
function openmira_render_memory_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $entries = openmira_get_memory_entries();
    ksort($entries);
    $editing_key = sanitize_text_field(openmira_memory_request_string($_GET['edit'] ?? null));
    $editing_entry = $editing_key !== '' && is_array($entries[$editing_key] ?? null) ? $entries[$editing_key] : null;
    $result = is_string($_GET['openmira_memory_result'] ?? null) ? $_GET['openmira_memory_result'] : '';
    $error = is_string($_GET['openmira_memory_error'] ?? null)
        ? sanitize_text_field(wp_unslash($_GET['openmira_memory_error']))
        : '';

    openmira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Memory', domain: 'open-mira'); ?></h1>
        <p><?php esc_html_e(
            'Review and manage persistent project facts stored by Open Mira MCP abilities.',
            domain: 'open-mira',
        ); ?></p>
        <?php openmira_render_memory_notice($result, $error); ?>
        <?php openmira_render_memory_editor($editing_key, $editing_entry); ?>
        <?php openmira_render_memory_table($entries); ?>
    </div>
    <?php
}

/**
 * Read a scalar request value as an unslashed string.
 */
function openmira_memory_request_string(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    return wp_unslash($value);
}

/**
 * Render memory action notices.
 */
function openmira_render_memory_notice(string $result, string $error): void
{
    if ($error !== '') {
        ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php

        return;
    }

    $message = match ($result) {
        'created' => __('Memory entry created.', domain: 'open-mira'),
        'updated' => __('Memory entry updated.', domain: 'open-mira'),
        'deleted' => __('Memory entry deleted.', domain: 'open-mira'),
        'not_found' => __('Memory entry was already absent.', domain: 'open-mira'),
        'cleared' => __('All memory entries cleared.', domain: 'open-mira'),
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
function openmira_render_memory_editor(string $editing_key, ?array $editing_entry): void
{
    $value = is_array($editing_entry) ? (string) ($editing_entry['value'] ?? '') : '';
    ?>
    <h2><?php echo
        $editing_key !== ''
            ? esc_html__('Edit Memory Entry', domain: 'open-mira')
            : esc_html__('Add Memory Entry', domain: 'open-mira')
    ; ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=openmira-memory')); ?>">
        <?php wp_nonce_field(action: 'openmira_memory_action', name: '_openmira_memory_nonce'); ?>
        <input type="hidden" name="openmira_memory_action" value="save">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="openmira-memory-key"><?php esc_html_e(
                    'Key',
                    domain: 'open-mira',
                ); ?></label></th>
                <td>
                    <input
                        id="openmira-memory-key"
                        class="regular-text"
                        name="memory_key"
                        pattern="^[a-z0-9][a-z0-9._-]{0,79}$"
                        required
                        value="<?php echo esc_attr($editing_key); ?>"
                    >
                    <p class="description"><?php esc_html_e(
                        'Use lowercase letters, numbers, dots, underscores, and hyphens. Maximum 80 characters.',
                        domain: 'open-mira',
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="openmira-memory-value"><?php esc_html_e(
                    'Value',
                    domain: 'open-mira',
                ); ?></label></th>
                <td>
                    <textarea id="openmira-memory-value" class="large-text code" rows="8" name="memory_value" required><?php echo
                        esc_textarea($value)
                    ; ?></textarea>
                    <p class="description"><?php esc_html_e(
                        'Store durable project facts only, not transient scratch notes.',
                        domain: 'open-mira',
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(
            $editing_key !== '' ? __('Update Memory', domain: 'open-mira') : __('Add Memory', domain: 'open-mira'),
        ); ?>
    </form>
    <?php
}

/**
 * Render the memory entries table.
 *
 * @param array<string, array<array-key, mixed>> $entries
 */
function openmira_render_memory_table(array $entries): void
{
    $export_url = wp_nonce_url(
        admin_url('admin.php?page=openmira-memory&openmira_export_memory=1'),
        action: 'openmira_export_memory',
    );
    ?>
    <hr>
    <h2><?php esc_html_e('Stored Entries', domain: 'open-mira'); ?></h2>
    <p>
        <a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e(
            'Export JSON',
            domain: 'open-mira',
        ); ?></a>
    </p>
    <?php openmira_render_memory_clear_form($entries); ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:220px;"><?php esc_html_e('Key', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Value', domain: 'open-mira'); ?></th>
                <th style="width:180px;"><?php esc_html_e('Updated', domain: 'open-mira'); ?></th>
                <th style="width:120px;"><?php esc_html_e('Updated By', domain: 'open-mira'); ?></th>
                <th style="width:170px;"><?php esc_html_e('Actions', domain: 'open-mira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($entries === []): ?>
                <tr><td colspan="5"><?php esc_html_e('No memory entries stored.', domain: 'open-mira'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $key => $entry): ?>
                <?php openmira_render_memory_row($key, $entry); ?>
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
function openmira_render_memory_clear_form(array $entries): void
{
    if ($entries === []) {
        return;
    }
    ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-memory'))
    ; ?>" style="margin: 8px 0 16px;">
        <?php wp_nonce_field(action: 'openmira_memory_action', name: '_openmira_memory_nonce'); ?>
        <input type="hidden" name="openmira_memory_action" value="clear">
        <button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo
            esc_js(__('Delete all memory entries?', domain: 'open-mira'))
        ; ?>');">
            <?php esc_html_e('Clear All Memory', domain: 'open-mira'); ?>
        </button>
    </form>
    <?php
}

/**
 * Render one memory row.
 *
 * @param array<array-key, mixed> $entry
 */
function openmira_render_memory_row(string $key, array $entry): void
{
    $edit_url = add_query_arg(['page' => 'openmira-memory', 'edit' => $key], admin_url('admin.php'));
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
                domain: 'open-mira',
            ); ?></a>
            <?php openmira_render_memory_delete_form($key); ?>
        </td>
    </tr>
    <?php
}

/**
 * Render a delete form for one memory key.
 */
function openmira_render_memory_delete_form(string $key): void
{ ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-memory'))
    ; ?>" style="display:inline;">
        <?php wp_nonce_field(action: 'openmira_memory_action', name: '_openmira_memory_nonce'); ?>
        <input type="hidden" name="openmira_memory_action" value="delete">
        <input type="hidden" name="memory_key" value="<?php echo esc_attr($key); ?>">
        <button type="submit" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo
            esc_js(__('Delete this memory entry?', domain: 'open-mira'))
        ; ?>');">
            <?php esc_html_e('Delete', domain: 'open-mira'); ?>
        </button>
    </form>
    <?php }
