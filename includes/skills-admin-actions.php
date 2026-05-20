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

const OPENMIRA_SKILL_BODY_MAX_BYTES = 65_536;

/**
 * Handle Skills admin actions with PRG redirects.
 */
function openmira_handle_skill_admin_actions(): void
{
    if (($_GET['page'] ?? null) !== 'openmira-skills') {
        return;
    }

    if (isset($_GET['openmira_skill_export'])) {
        openmira_handle_skill_export_action();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = openmira_skill_request_string($_POST['openmira_skill_action'] ?? null);
    $result = match ($action) {
        'save' => openmira_handle_skill_save_action($_POST),
        'delete' => openmira_handle_skill_delete_action($_POST),
        'customize' => openmira_handle_skill_customize_action($_POST),
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
    if ($result['status'] === 'customized' && is_string($result['id'] ?? null)) {
        $redirect_args['skill_action'] = 'edit';
        $redirect_args['skill_id'] = $result['id'];
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit();
}

/**
 * Handle create/update requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
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
 * Handle delete requests.
 *
 * @param array<array-key, mixed>|null $input
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_handle_skill_delete_action(?array $input = null): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to delete Open Mira Skills.',
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
        $body = file_get_contents($skill['path']);
        if ($body === false) {
            wp_die(esc_html__('Could not read the skill file.', domain: 'open-mira'));
            return;
        }
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

    openmira_stream_file_download('openmira-skills.zip', 'application/zip', $zip);
}

/**
 * Handle import requests.
 *
 * @param array<array-key, mixed>|null $input
 * @param array<array-key, mixed>|null $files
 * @return array{status: string, imported: int, updated: int, skipped: int}|WP_Error
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

    $upload = openmira_skill_import_upload_info($files);
    if ($upload instanceof WP_Error) {
        return $upload;
    }
    $skip_existing = array_key_exists('skip_existing', $input) && $input['skip_existing'] !== '';
    $single_skill_id = openmira_skill_request_string($input['single_skill_id'] ?? null);

    if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) === 'zip') {
        return openmira_import_skills_zip($upload['tmp_name'], $skip_existing);
    }

    if ($single_skill_id === '') {
        $filename_id = preg_replace(pattern: '/\.(md|markdown)$/i', replacement: '', subject: $upload['name']);
        $single_skill_id = is_string($filename_id) ? $filename_id : '';
    }
    if ($single_skill_id === '' || strtoupper($single_skill_id) === 'SKILL') {
        return new WP_Error(
            'openmira_skill_import_missing_id',
            'Provide a Skill ID when importing a single SKILL.md file.',
        );
    }

    $raw = file_get_contents($upload['tmp_name']);
    if (!is_string($raw)) {
        return new WP_Error('openmira_skill_import_read_failed', 'Could not read the uploaded skill file.');
    }

    return openmira_import_skill_markdown($single_skill_id, $raw, $skip_existing);
}

/**
 * Return uploaded skill file metadata.
 *
 * @param array<array-key, mixed> $files
 * @return array{name: string, tmp_name: string}|WP_Error
 */
function openmira_skill_import_upload_info(array $files): array|WP_Error
{
    $upload = is_array($files['skill_import_file'] ?? null) ? $files['skill_import_file'] : [];
    $tmp_name = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
    $name = is_string($upload['name'] ?? null) ? $upload['name'] : '';
    if ($tmp_name === '' || !is_readable($tmp_name)) {
        return new WP_Error('openmira_skill_import_missing_file', 'Choose a SKILL.md file or ZIP archive to import.');
    }

    return ['name' => $name, 'tmp_name' => $tmp_name];
}

/**
 * Save one user skill.
 *
 * @param array{id?: string, title?: string, description?: string, body?: string} $input
 * @return array{status: string, id: string}|WP_Error
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

    $dir_ok = openmira_ensure_user_skills_dir();
    if (is_wp_error($dir_ok)) {
        return $dir_ok;
    }

    $file = openmira_user_skill_file_path($skill_id);
    $dir = dirname($file);
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return new WP_Error('openmira_skill_write_failed', 'Could not create the skill directory.');
    }

    $created = !is_file($file);
    $markdown = openmira_build_skill_markdown($title, $description, $body);
    if (file_put_contents($file, $markdown, LOCK_EX) === false) {
        return new WP_Error('openmira_skill_write_failed', 'Could not write the skill file.');
    }

    return [
        'status' => $created ? 'created' : 'updated',
        'id' => $skill_id,
    ];
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

    $file = openmira_user_skill_file_path($skill_id);
    if (!is_file($file)) {
        return new WP_Error(
            'openmira_skill_not_user_editable',
            'Only user skills can be deleted. Built-in skills are read-only.',
        );
    }

    if (!unlink($file)) {
        return new WP_Error('openmira_skill_delete_failed', 'Could not delete the user skill file.');
    }

    $dir = dirname($file);
    $remaining = is_dir($dir) ? scandir($dir) : false;
    if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
        rmdir($dir);
    }

    return ['status' => 'deleted', 'id' => $skill_id];
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

    $built_in = openmira_scan_skills_directory(OPENMIRA_SKILLS_DIR, source: 'built-in');
    if (!array_key_exists($skill_id, $built_in)) {
        return new WP_Error('openmira_skill_not_built_in', 'Only built-in skills can be customized.');
    }

    $target = openmira_user_skill_file_path($skill_id);
    if (is_file($target)) {
        return new WP_Error('openmira_skill_already_customized', 'This skill already has a user override.');
    }

    $dir_ok = openmira_ensure_user_skills_dir();
    if (is_wp_error($dir_ok)) {
        return $dir_ok;
    }
    if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) {
        return new WP_Error('openmira_skill_write_failed', 'Could not create the skill directory.');
    }
    if (!copy($built_in[$skill_id]['path'], $target)) {
        return new WP_Error('openmira_skill_write_failed', 'Could not copy the built-in skill.');
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

    $user_file = openmira_user_skill_file_path($skill_id);
    if ($skip_existing && is_file($user_file)) {
        return ['status' => 'imported-0-updated-0-skipped-1', 'imported' => 0, 'updated' => 0, 'skipped' => 1];
    }

    $parsed = openmira_parse_skill_markdown($raw);
    $body = $parsed['body'];
    if (strlen($body) > OPENMIRA_SKILL_BODY_MAX_BYTES) {
        return new WP_Error('openmira_skill_body_too_large', 'Skill body must not exceed 64 KB.');
    }

    $created = !is_file($user_file);
    $result = openmira_save_user_skill([
        'id' => $skill_id,
        'title' => $parsed['title'] !== '' ? $parsed['title'] : $skill_id,
        'description' => $parsed['description'] !== '' ? $parsed['description'] : 'Imported Open Mira Skill.',
        'body' => $body,
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
        if (!is_string($name) || preg_match('#^([a-z0-9][\-a-z0-9._]{0,79})/SKILL\.md$#', $name, $match) !== 1) {
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

    $skills = array_filter(openmira_get_skills(), static fn(array $skill): bool => $skill['source'] === 'user');
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
        $contents = file_get_contents($skill['path']);
        if (!is_string($contents)) {
            continue;
        }
        $zip->addFromString($skill['id'] . '/SKILL.md', $contents);
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
