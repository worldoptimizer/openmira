<?php

declare(strict_types=1);

/**
 * Admin UI for persistent Open Mira project memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_enqueue_scripts', static function (string $hook_suffix): void {
    if ($hook_suffix !== 'open-mira_page_openmira-memory') {
        return;
    }

    openmira_enqueue_admin_list_styles();
    openmira_enqueue_markdown_editor('openmira-memory-value');
});

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
        'import' => openmira_handle_memory_import_action(),
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
 * Import memory entries from an uploaded JSON export.
 *
 * @return string|WP_Error
 */
function openmira_handle_memory_import_action(?array $input = null, ?array $files = null): string|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('memory_import_permission_denied', 'You do not have permission to import memory entries.');
    }

    $input ??= $_POST;
    $files ??= $_FILES;
    $upload = is_array($files['memory_import_file'] ?? null) ? $files['memory_import_file'] : [];
    $tmp_name = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
    $raw = '';
    if ($tmp_name !== '' && is_readable($tmp_name)) {
        $file_contents = file_get_contents($tmp_name);
        if (!is_string($file_contents)) {
            return new WP_Error('memory_import_read_failed', 'Could not read the uploaded memory export.');
        }
        $raw = $file_contents;
    }
    if ($raw === '') {
        $raw = openmira_memory_request_string($input['memory_import_json'] ?? null);
    }
    if (trim($raw) === '') {
        return new WP_Error('memory_import_missing_file', 'Choose a memory JSON export or paste JSON to import.');
    }

    $decoded = json_decode($raw, associative: true);
    if (!is_array($decoded)) {
        return new WP_Error('memory_import_invalid_json', 'Memory import file must be valid JSON.');
    }

    $skip_existing = array_key_exists('skip_existing', $input) && $input['skip_existing'] !== '';
    $result = openmira_import_memory_entries($decoded, $skip_existing);
    if (is_wp_error($result)) {
        return $result;
    }

    return sprintf('imported-%d-updated-%d-skipped-%d', $result['imported'], $result['updated'], $result['skipped']);
}

/**
 * Import memory entries from a decoded export payload.
 *
 * @param array<array-key, mixed> $payload
 * @return array{imported: int, updated: int, skipped: int}|WP_Error
 */
function openmira_import_memory_entries(array $payload, bool $skip_existing): array|WP_Error
{
    $incoming = is_array($payload['entries'] ?? null) ? $payload['entries'] : null;
    if ($incoming === null) {
        return new WP_Error('memory_import_invalid_shape', 'Memory import must contain an entries object.');
    }

    $entries = openmira_get_memory_entries();
    $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
    foreach ($incoming as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }
        $valid = openmira_validate_memory_key($key);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (!array_key_exists('value', $entry)) {
            return new WP_Error('memory_import_missing_value', 'Each imported memory entry must contain a value.');
        }
        $value = (string) $entry['value'];
        if (strlen($value) > 20_000) {
            return new WP_Error('memory_value_too_large', 'Memory value must not exceed 20000 bytes.');
        }
        if ($skip_existing && array_key_exists($key, $entries)) {
            $counts['skipped']++;
            continue;
        }

        $exists = array_key_exists($key, $entries);
        $entries[$key] = [
            'value' => $value,
            'updated_at' => is_string($entry['updated_at'] ?? null)
                ? sanitize_text_field((string) $entry['updated_at'])
                : current_time(type: 'mysql', gmt: true),
            'updated_by' => array_key_exists('updated_by', $entry) ? (int) $entry['updated_by'] : get_current_user_id(),
        ];
        if ($exists) {
            $counts['updated']++;
            continue;
        }
        $counts['imported']++;
    }

    ksort($entries);
    openmira_update_memory_entries($entries);

    return $counts;
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

    $message = match (true) {
        $result === 'created' => __('Memory entry created.', domain: 'open-mira'),
        $result === 'updated' => __('Memory entry updated.', domain: 'open-mira'),
        $result === 'deleted' => __('Memory entry deleted.', domain: 'open-mira'),
        $result === 'not_found' => __('Memory entry was already absent.', domain: 'open-mira'),
        $result === 'cleared' => __('All memory entries cleared.', domain: 'open-mira'),
        str_starts_with($result, 'imported-') => sprintf(
            /* translators: %s is an import result summary. */
            __('Memory import complete: %s.', domain: 'open-mira'),
            $result,
        ),
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
                        pattern="^[a-z0-9][\-a-z0-9._]{0,79}$"
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
                    <textarea id="openmira-memory-value" class="large-text code" rows="8" name="memory_value"><?php echo
                        esc_textarea($value)
                    ; ?></textarea>
                    <p class="description"><?php esc_html_e(
                        'Store durable project facts only, not transient scratch notes.',
                        domain: 'open-mira',
                    ); ?></p>
                </td>
            </tr>
        </table>
        <p class="openmira-admin-form-actions">
            <?php submit_button(
                text: $editing_key !== ''
                    ? __('Update Memory', domain: 'open-mira')
                    : __('Add Memory', domain: 'open-mira'),
                type: 'primary',
                name: 'submit',
                wrap: false,
            ); ?>
            <a class="button button-secondary" href="<?php echo
                esc_url(admin_url('admin.php?page=openmira-memory'))
            ; ?>"><?php esc_html_e('Cancel', domain: 'open-mira'); ?></a>
        </p>
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
    <div class="openmira-admin-toolbar">
        <a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e(
            'Export JSON',
            domain: 'open-mira',
        ); ?></a>
        <button type="button" class="button" onclick="document.getElementById('openmira-memory-import-panel').toggleAttribute('hidden');"><?php esc_html_e(
            'Import JSON',
            domain: 'open-mira',
        ); ?></button>
    </div>
    <?php openmira_render_memory_import_form(); ?>
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
 * Render the memory import form.
 */
function openmira_render_memory_import_form(): void
{ ?>
    <div id="openmira-memory-import-panel" class="card" style="max-width: 760px; margin: 0 0 16px;" hidden>
        <h2><?php esc_html_e('Import Memory', domain: 'open-mira'); ?></h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo
            esc_url(admin_url('admin.php?page=openmira-memory'))
        ; ?>">
            <?php wp_nonce_field(action: 'openmira_memory_action', name: '_openmira_memory_nonce'); ?>
            <input type="hidden" name="openmira_memory_action" value="import">
            <p>
                <label for="openmira-memory-import-file"><strong><?php esc_html_e(
                    'Memory JSON export',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <input id="openmira-memory-import-file" type="file" name="memory_import_file" accept="application/json,.json">
            </p>
            <p>
                <label for="openmira-memory-import-json"><strong><?php esc_html_e(
                    'Or paste JSON',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <textarea id="openmira-memory-import-json" class="large-text code" rows="6" name="memory_import_json"></textarea>
            </p>
            <p>
                <label><input type="checkbox" name="skip_existing" value="1"> <?php esc_html_e(
                    'Skip existing memory keys',
                    domain: 'open-mira',
                ); ?></label>
            </p>
            <?php submit_button(__('Import Memory', domain: 'open-mira'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php }

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
            <a class="button button-small openmira-admin-action-link" href="<?php echo
                esc_url($edit_url)
            ; ?>"><?php esc_html_e('Edit', domain: 'open-mira'); ?></a>
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
        <button type="submit" class="button button-small openmira-admin-action-link" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('<?php echo
            esc_js(__('Delete this memory entry?', domain: 'open-mira'))
        ; ?>');">
            <?php esc_html_e('Delete', domain: 'open-mira'); ?>
        </button>
    </form>
    <?php }
