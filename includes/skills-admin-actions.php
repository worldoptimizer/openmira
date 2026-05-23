<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Admin actions for user-editable Open Mira Skills.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle Skills admin actions with PRG redirects.
 */
function openmira_handle_skill_admin_actions(): void
{
    if (($_GET['page'] ?? null) !== 'openmira-skills') {
        return;
    }

    if (array_key_exists('openmira_skill_export', $_GET)) {
        openmira_handle_skill_export_action();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = openmira_skill_request_string($_POST['openmira_skill_action'] ?? null);
    $result = match ($action) {
        'save' => openmira_handle_skill_save_action($_POST),
        'trash' => openmira_handle_skill_trash_action($_POST),
        'restore' => openmira_handle_skill_restore_action($_POST),
        'delete' => openmira_handle_skill_delete_action($_POST),
        'customize' => openmira_handle_skill_customize_action($_POST),
        'toggle_prompt' => openmira_handle_skill_toggle_prompt_action($_POST),
        'import' => openmira_handle_skill_import_action($_POST, $_FILES),
        default => new WP_Error('openmira_invalid_skill_action', 'Invalid skill action.'),
    };

    $redirect_args = ['page' => 'openmira-skills'];
    if (is_wp_error($result)) {
        $redirect_args['openmira_skill_error'] = rawurlencode($result->get_error_message());
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit();
    }

    $redirect_args['openmira_skill_result'] = $result['status'];
    if (is_array($result['file_results'] ?? null)) {
        $notice_key = openmira_store_skill_import_results($result);
        if ($notice_key !== '') {
            $redirect_args['openmira_skill_import_key'] = $notice_key;
        }
    }
    if ($result['status'] === 'customized' && is_string($result['id'] ?? null)) {
        $redirect_args['skill_action'] = 'edit';
        $redirect_args['skill_id'] = $result['id'];
    }
    if ($result['status'] === 'trashed' || $result['status'] === 'deleted') {
        $redirect_args['skill_status'] = 'trashed';
    }
    if ($result['status'] === 'restored') {
        $redirect_args['skill_status'] = 'active';
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit();
}

/**
 * Handle trash requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_handle_skill_trash_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to trash Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    return openmira_trash_user_skill($skill_id);
}

/**
 * Handle restore requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_handle_skill_restore_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to restore Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    return openmira_restore_user_skill($skill_id);
}

/**
 * Handle prompt enable/disable requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string, post_id?: int}|WP_Error
 */
function openmira_handle_skill_toggle_prompt_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to update Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    $enabled = openmira_skill_request_string($input['enable_prompt'] ?? null) === '1';
    return openmira_set_cpt_skill_prompt_enabled($skill_id, $enabled);
}

/**
 * Handle create/update requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string, post_id?: int}|WP_Error
 */
function openmira_handle_skill_save_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('openmira_skill_permission_denied', 'You do not have permission to save Open Mira Skills.');
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    $title = sanitize_text_field(openmira_skill_request_string($input['skill_title'] ?? null));
    $description = sanitize_text_field(openmira_skill_request_string($input['skill_description'] ?? null));
    $body = openmira_skill_request_string($input['skill_body'] ?? null);

    return openmira_save_user_skill([
        'id' => $skill_id,
        'title' => $title,
        'description' => $description,
        'body' => $body,
    ]);
}

/**
 * Handle permanent delete requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_handle_skill_delete_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to permanently delete Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    return openmira_delete_user_skill($skill_id);
}

/**
 * Handle customize requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_handle_skill_customize_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to customize Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $skill_id = openmira_skill_request_string($input['skill_id'] ?? null);
    return openmira_customize_built_in_skill($skill_id);
}

/**
 * Handle export requests.
 */
function openmira_handle_skill_export_action(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to export Open Mira Skills.', domain: 'open-mira'));
    }

    check_admin_referer(action: 'openmira_skill_export');
    $skill_id = openmira_skill_request_string($_GET['skill_id'] ?? null);
    if ($skill_id !== '') {
        $skill = openmira_get_skill($skill_id);
        if ($skill === null) {
            wp_die(esc_html__('Skill not found.', domain: 'open-mira'));
            return;
        }
        $body = openmira_build_skill_markdown(
            (string) $skill['title'],
            (string) $skill['description'],
            (string) $skill['body'],
            ($skill['enabled'] ?? true) !== false,
        );
        openmira_stream_download(
            filename: $skill_id . '-SKILL.md',
            content_type: 'text/markdown; charset=utf-8',
            body: $body,
        );
    }

    $zip = openmira_create_user_skills_zip();
    if ($zip instanceof WP_Error) {
        wp_die(esc_html($zip->get_error_message()));
        return;
    }

    openmira_stream_file_download(filename: 'openmira-skills.zip', content_type: 'application/zip', path: $zip);
}

/**
 * Handle import requests.
 *
 * @param array<array-key, mixed>|null $input
 * @param array<array-key, mixed>|null $files
 * @return array<string, mixed>|WP_Error
 */
function openmira_handle_skill_import_action(?array $input = null, ?array $files = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to import Open Mira Skills.',
        );
    }

    $input ??= $_POST;
    $files ??= $_FILES;
    if ($input === $_POST) {
        check_admin_referer(action: 'openmira_skill_action', query_arg: '_openmira_skill_nonce');
    }

    $uploads = openmira_skill_import_uploads_info($files);
    if ($uploads instanceof WP_Error) {
        return $uploads;
    }
    $skip_existing = array_key_exists('skip_existing', $input) && $input['skip_existing'] !== '';
    $single_skill_id = openmira_skill_request_string($input['single_skill_id'] ?? null);

    $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $file_results = [];
    $is_single_upload = count($uploads) === 1;
    foreach ($uploads as $upload) {
        $result = openmira_import_one_uploaded_skill_file(
            upload: $upload,
            skip_existing: $skip_existing,
            single_skill_id: $is_single_upload ? $single_skill_id : '',
        );
        if (is_wp_error($result)) {
            $counts['failed']++;
            $file_results[] = [
                'file' => $upload['name'],
                'status' => 'failed',
                'message' => $result->get_error_message(),
            ];
            continue;
        }

        foreach (['imported', 'updated', 'skipped'] as $count_key) {
            $counts[$count_key] += (int) ($result[$count_key] ?? 0);
        }
        $file_results[] = [
            'file' => $upload['name'],
            'status' => (string) ($result['status'] ?? 'imported'),
            'message' => (string) ($result['message'] ?? openmira_skill_import_result_message($result)),
        ];
    }

    return array_merge($counts, [
        'status' => openmira_skill_import_status($counts),
        'file_results' => $file_results,
    ]);
}

/**
 * Import one uploaded SKILL.md or ZIP file.
 *
 * @param array{name: string, tmp_name: string} $upload
 * @return array<string, mixed>|WP_Error
 */
function openmira_import_one_uploaded_skill_file(
    array $upload,
    bool $skip_existing,
    string $single_skill_id,
): array|WP_Error {
    if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) === 'zip') {
        return openmira_import_skills_zip($upload['tmp_name'], $skip_existing);
    }

    $skill_id = $single_skill_id;
    if ($skill_id === '') {
        $filename_id = preg_replace(pattern: '/\.(md|markdown)$/i', replacement: '', subject: $upload['name']);
        $skill_id = is_string($filename_id) ? $filename_id : '';
    }
    if ($skill_id === '' || strtoupper($skill_id) === 'SKILL') {
        return new WP_Error(
            'openmira_skill_import_missing_id',
            'Provide a Skill ID when importing a single SKILL.md file, or name each file after its Skill ID.',
        );
    }

    $raw = file_get_contents($upload['tmp_name']);
    if (!is_string($raw)) {
        return new WP_Error('openmira_skill_import_read_failed', 'Could not read the uploaded skill file.');
    }

    return openmira_import_skill_markdown($skill_id, $raw, $skip_existing);
}

/**
 * Return uploaded skill file metadata.
 *
 * @param array<array-key, mixed> $files
 * @return list<array{name: string, tmp_name: string}>|WP_Error
 */
function openmira_skill_import_uploads_info(array $files): array|WP_Error
{
    $upload = is_array($files['skill_import_file'] ?? null) ? $files['skill_import_file'] : [];
    $tmp_names = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [$upload['tmp_name'] ?? ''];
    $names = is_array($upload['name'] ?? null) ? $upload['name'] : [$upload['name'] ?? ''];
    $uploads = [];
    foreach ($tmp_names as $index => $tmp_name) {
        $tmp_name = is_string($tmp_name) ? $tmp_name : '';
        $name = is_string($names[$index] ?? null) ? $names[$index] : '';
        if ($tmp_name === '' || !is_readable($tmp_name)) {
            continue;
        }
        $uploads[] = ['name' => $name, 'tmp_name' => $tmp_name];
    }

    if ($uploads === []) {
        return new WP_Error('openmira_skill_import_missing_file', 'Choose a SKILL.md file or ZIP archive to import.');
    }

    return $uploads;
}

/**
 * Store a per-file import summary for the next PRG page load.
 *
 * @param array<string, mixed> $result
 */
function openmira_store_skill_import_results(array $result): string
{
    $generated_key = wp_generate_uuid4();
    $key = is_string($generated_key) && $generated_key !== ''
        ? $generated_key
        : md5(uniqid('openmira_skill_import_', true));
    set_transient('openmira_skill_import_' . get_current_user_id() . '_' . $key, $result, 5 * 60);
    return $key;
}

/**
 * Fetch and clear a per-file import summary.
 *
 * @return array<string, mixed>|null
 */
function openmira_take_skill_import_results(string $key): ?array
{
    if ($key === '') {
        return null;
    }
    $transient_key = 'openmira_skill_import_' . get_current_user_id() . '_' . $key;
    $result = get_transient($transient_key);
    delete_transient($transient_key);

    if (!is_array($result)) {
        return null;
    }

    /** @var array<string, mixed> $result */
    return $result;
}

/**
 * Return the compact import status token used in PRG redirects.
 *
 * @param array{imported: int, updated: int, skipped: int, failed?: int} $counts
 */
function openmira_skill_import_status(array $counts): string
{
    return sprintf(
        'imported-%d-updated-%d-skipped-%d-failed-%d',
        $counts['imported'],
        $counts['updated'],
        $counts['skipped'],
        (int) ($counts['failed'] ?? 0),
    );
}

/**
 * Return a human-readable per-file import message.
 *
 * @param array<string, mixed> $result
 */
function openmira_skill_import_result_message(array $result): string
{
    $imported = (int) ($result['imported'] ?? 0);
    $updated = (int) ($result['updated'] ?? 0);
    $skipped = (int) ($result['skipped'] ?? 0);

    if ($imported > 0 && $updated === 0 && $skipped === 0) {
        return sprintf('%d imported', $imported);
    }
    if ($updated > 0 && $imported === 0 && $skipped === 0) {
        return sprintf('%d updated', $updated);
    }
    if ($skipped > 0 && $imported === 0 && $updated === 0) {
        return sprintf('%d skipped (already exists)', $skipped);
    }

    return sprintf('%d imported, %d updated, %d skipped', $imported, $updated, $skipped);
}

/**
 * Save one user skill.
 *
 * @param array{id?: string, title?: string, description?: string, body?: string, enabled?: bool} $input
 * @return array{status: string, id: string, post_id?: int}|WP_Error
 */
function openmira_save_user_skill(array $input): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('openmira_skill_permission_denied', 'You do not have permission to save Open Mira Skills.');
    }

    $skill_id = $input['id'] ?? '';
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $body = $input['body'] ?? '';
    if ($title === '') {
        return new WP_Error('openmira_skill_missing_title', 'Skill title is required.');
    }
    if ($description === '') {
        return new WP_Error('openmira_skill_missing_description', 'Skill description is required.');
    }
    if ($body === '') {
        return new WP_Error('openmira_skill_missing_body', 'Skill body is required.');
    }
    if (strlen($body) > OPENMIRA_SKILL_BODY_MAX_BYTES) {
        return new WP_Error('openmira_skill_body_too_large', 'Skill body must not exceed 64 KB.');
    }

    $existing_post = openmira_get_cpt_skill_post($skill_id);
    $enabled = array_key_exists('enabled', $input)
        ? $input['enabled'] !== false
        : ($existing_post instanceof WP_Post ? openmira_is_cpt_skill_prompt_enabled($existing_post->ID) : true);

    return openmira_upsert_cpt_skill([
        'id' => $skill_id,
        'title' => $title,
        'description' => $description,
        'body' => $body,
        'enabled' => $enabled,
    ]);
}

/**
 * Delete one user skill.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_delete_user_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    return openmira_delete_cpt_skill($skill_id);
}

/**
 * Trash one user skill.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_trash_user_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    return openmira_trash_cpt_skill($skill_id);
}

/**
 * Restore one user skill.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_restore_user_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    return openmira_restore_cpt_skill($skill_id);
}

/**
 * Copy a built-in skill into user-content for editing.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_customize_built_in_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $built_in = openmira_scan_skills_directory(OPENMIRA_SKILLS_DIR, source: 'filesystem');
    if (!array_key_exists($skill_id, $built_in)) {
        return new WP_Error('openmira_skill_not_built_in', 'Only built-in skills can be customized.');
    }

    if (openmira_get_cpt_skill_post($skill_id) instanceof WP_Post) {
        return new WP_Error('openmira_skill_already_customized', 'This skill already has a user override.');
    }

    $result = openmira_upsert_cpt_skill([
        'id' => $skill_id,
        'title' => $built_in[$skill_id]['title'],
        'description' => $built_in[$skill_id]['description'],
        'body' => $built_in[$skill_id]['body'],
        'enabled' => ($built_in[$skill_id]['enabled'] ?? true) !== false,
    ]);
    if (is_wp_error($result)) {
        return $result;
    }

    return ['status' => 'customized', 'id' => $skill_id];
}

/**
 * Import one SKILL.md document.
 *
 * @return array{status: string, imported: int, updated: int, skipped: int}|WP_Error
 */
function openmira_import_skill_markdown(string $skill_id, string $raw, bool $skip_existing): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $existing = openmira_get_cpt_skill_post($skill_id);
    if ($skip_existing && $existing instanceof WP_Post) {
        return ['status' => 'imported-0-updated-0-skipped-1', 'imported' => 0, 'updated' => 0, 'skipped' => 1];
    }

    $parsed = openmira_parse_skill_markdown($raw);
    if ($parsed['title'] === '' || $parsed['description'] === '') {
        return new WP_Error(
            'openmira_skill_invalid_frontmatter',
            'Invalid SKILL.md frontmatter. A title and description are required.',
        );
    }

    $body = $parsed['body'];
    if (strlen($body) > OPENMIRA_SKILL_BODY_MAX_BYTES) {
        return new WP_Error('openmira_skill_body_too_large', 'Skill body must not exceed 64 KB.');
    }

    $created = !$existing instanceof WP_Post;
    $result = openmira_save_user_skill([
        'id' => $skill_id,
        'title' => $parsed['title'],
        'description' => $parsed['description'],
        'body' => $body,
        'enabled' =>
            $parsed['enable_prompt']
                ?? ($existing instanceof WP_Post ? openmira_is_cpt_skill_prompt_enabled($existing->ID) : true),
    ]);
    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'status' => $created ? 'imported-1-updated-0-skipped-0' : 'imported-0-updated-1-skipped-0',
        'imported' => $created ? 1 : 0,
        'updated' => $created ? 0 : 1,
        'skipped' => 0,
    ];
}

/**
 * Import a ZIP archive of skills.
 *
 * @return array{status: string, imported: int, updated: int, skipped: int}|WP_Error
 */
function openmira_import_skills_zip(string $zip_path, bool $skip_existing): array|WP_Error
{
    if (!class_exists('ZipArchive')) {
        return new WP_Error('openmira_zip_unavailable', 'ZipArchive is not available on this server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return new WP_Error('openmira_skill_zip_open_failed', 'Could not open the uploaded ZIP archive.');
    }

    $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        $match = [];
        if (!is_string($name) || preg_match('#^([a-z0-9][-a-z0-9._]{0,79})/SKILL\.md$#', $name, $match) !== 1) {
            continue;
        }

        $raw = $zip->getFromIndex($index);
        if (!is_string($raw)) {
            continue;
        }

        $result = openmira_import_skill_markdown($match[1], $raw, $skip_existing);
        if (is_wp_error($result)) {
            $zip->close();
            return $result;
        }
        $counts['imported'] += $result['imported'];
        $counts['updated'] += $result['updated'];
        $counts['skipped'] += $result['skipped'];
    }
    $zip->close();

    $counts['status'] = sprintf(
        'imported-%d-updated-%d-skipped-%d',
        $counts['imported'],
        $counts['updated'],
        $counts['skipped'],
    );

    return $counts;
}

/**
 * Create a ZIP archive of all user skills.
 */
function openmira_create_user_skills_zip(): string|WP_Error
{
    if (!class_exists('ZipArchive')) {
        return new WP_Error('openmira_zip_unavailable', 'ZipArchive is not available on this server.');
    }

    $skills = array_filter(openmira_get_skills(), static fn(array $skill): bool => $skill['source'] === 'cpt');
    if ($skills === []) {
        return new WP_Error('openmira_no_user_skills', 'There are no user skills to export.');
    }

    $path = wp_tempnam('openmira-skills.zip');
    if ($path === '') {
        return new WP_Error('openmira_zip_create_failed', 'Could not create a temporary ZIP file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('openmira_zip_create_failed', 'Could not create the skills ZIP archive.');
    }

    foreach ($skills as $skill) {
        $contents = openmira_build_skill_markdown(
            (string) $skill['title'],
            (string) $skill['description'],
            (string) $skill['body'],
            ($skill['enabled'] ?? true) !== false,
        );
        $zip->addFromString((string) $skill['id'] . '/SKILL.md', $contents);
    }
    $zip->close();

    return $path;
}

/**
 * Return one user skill file path.
 */
function openmira_user_skill_file_path(string $skill_id): string
{
    return rtrim(openmira_user_skills_dir(), characters: '/\\') . '/' . $skill_id . '/SKILL.md';
}

/**
 * Stream string content as a download.
 */
function openmira_stream_download(string $filename, string $content_type, string $body): void
{
    nocache_headers();
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    echo $body;
    exit();
}

/**
 * Stream a local file as a download.
 */
function openmira_stream_file_download(string $filename, string $content_type, string $path): void
{
    nocache_headers();
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    readfile($path);
    if (is_file($path)) {
        unlink($path);
    }
    exit();
}

/**
 * Read an unslashed scalar request value.
 */
function openmira_skill_request_string(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    return wp_unslash($value);
}
