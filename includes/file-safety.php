<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Shared file safety helpers: read tracking, diffs, backups, and audit events.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_FILE_BACKUPS_OPTION = 'openmira_file_backups';

const OPENMIRA_FILE_BACKUPS_DIR = WP_CONTENT_DIR . '/openmira-file-backups';

const OPENMIRA_FILE_BACKUPS_MAX = 25;

const OPENMIRA_AUDIT_LOG_OPTION = 'openmira_audit_log';

const OPENMIRA_AUDIT_LOG_MAX = 200;

const OPENMIRA_AUDIT_DIFF_MAX_BYTES = 60_000;

const OPENMIRA_READ_TRACKING_TTL = 12 * HOUR_IN_SECONDS;

const OPENMIRA_PLAN_ACT_REQUIRED_OPTION = 'openmira_plan_act_required';

const OPENMIRA_MINUTE_IN_SECONDS = 60;

const OPENMIRA_DAY_IN_SECONDS = 86_400;

/**
 * Return a stable content hash.
 */
function openmira_file_hash_content(string $content): string
{
    return hash(algo: 'sha256', data: $content);
}

/**
 * Return a stable file identity hash.
 */
function openmira_hash_file_path(string $resolved): string
{
    return hash(algo: 'sha256', data: wp_normalize_path($resolved));
}

/**
 * Return a compact path for user-facing metadata.
 */
function openmira_display_path(string $path): string
{
    $normalized_path = wp_normalize_path($path);
    $normalized_abspath = wp_normalize_path(ABSPATH);
    $normalized_content = wp_normalize_path(WP_CONTENT_DIR);

    if (str_starts_with($normalized_path, $normalized_abspath)) {
        return ltrim(string: substr($normalized_path, strlen($normalized_abspath)), characters: '/');
    }

    if (str_starts_with($normalized_path, $normalized_content)) {
        return 'wp-content/' . ltrim(string: substr($normalized_path, strlen($normalized_content)), characters: '/');
    }

    return $normalized_path;
}

/**
 * Mark a file as read by the current user.
 */
function openmira_mark_file_read(string $resolved): void
{
    if (!is_file($resolved) || !is_readable($resolved)) {
        return;
    }

    $hash = hash_file(algo: 'sha256', filename: $resolved);
    if (!is_string($hash)) {
        return;
    }

    $key = openmira_read_tracking_key();
    $state = openmira_get_read_tracking_state();
    $state[openmira_hash_file_path($resolved)] = [
        'path' => openmira_display_path($resolved),
        'hash' => $hash,
        'read_at' => gmdate('c'),
    ];

    set_transient($key, $state, (int) OPENMIRA_READ_TRACKING_TTL);
}

/**
 * Return whether a file has been read by the current user in this tracking window.
 *
 * @return array{read: bool, hash: string, read_at: string}
 */
function openmira_get_file_read_state(string $resolved): array
{
    $state = openmira_get_read_tracking_state();
    $entry = $state[openmira_hash_file_path($resolved)] ?? null;
    if (!is_array($entry)) {
        return ['read' => false, 'hash' => '', 'read_at' => ''];
    }

    return [
        'read' => true,
        'hash' => (string) ($entry['hash'] ?? ''),
        'read_at' => (string) ($entry['read_at'] ?? ''),
    ];
}

/**
 * Require an existing file to have been read and remain unchanged before mutation.
 *
 * @return true|WP_Error
 */
function openmira_require_fresh_file_read(string $resolved, string $ability, string $expected_hash = ''): bool|WP_Error
{
    if (!is_file($resolved)) {
        return true;
    }

    $current_hash = hash_file(algo: 'sha256', filename: $resolved);
    if (!is_string($current_hash)) {
        return new WP_Error('openmira_hash_failed', sprintf(
            'Could not hash file before running %s: %s',
            $ability,
            openmira_display_path($resolved),
        ));
    }

    if ($expected_hash !== '') {
        if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return new WP_Error('openmira_invalid_expected_hash', sprintf(
                'expected_current_hash for %s must be a SHA-256 hash.',
                openmira_display_path($resolved),
            ));
        }

        if ($expected_hash === $current_hash) {
            return true;
        }

        return new WP_Error(
            'openmira_expected_hash_mismatch',
            sprintf(
                'File hash does not match expected_current_hash: %s. Read it again before running %s.',
                openmira_display_path($resolved),
                $ability,
            ),
            [
                'path' => openmira_display_path($resolved),
                'expected_hash' => $expected_hash,
                'current_hash' => $current_hash,
            ],
        );
    }

    $read_state = openmira_get_file_read_state($resolved);
    if (!$read_state['read']) {
        return new WP_Error(
            'openmira_file_not_read',
            sprintf(
                'Read %s before running %s, or pass expected_current_hash from a recent read/scaffold response. Existing files require stale-write protection.',
                openmira_display_path($resolved),
                $ability,
            ),
            [
                'path' => openmira_display_path($resolved),
                'hint' => 'Use read-file first, or pass a matching content_hash as expected_current_hash when you already have one.',
            ],
        );
    }

    if ($read_state['hash'] !== $current_hash) {
        return new WP_Error(
            'openmira_stale_file_read',
            sprintf(
                'File changed since it was read: %s. Read it again before running %s.',
                openmira_display_path($resolved),
                $ability,
            ),
            [
                'path' => openmira_display_path($resolved),
                'read_hash' => $read_state['hash'],
                'current_hash' => $current_hash,
                'read_at' => $read_state['read_at'],
            ],
        );
    }

    return true;
}

/**
 * Validate a written PHP file and roll it back if php -l fails.
 *
 * @param array<array-key, mixed>|null $backup
 * @return true|WP_Error
 */
// @mago-expect lint:excessive-parameter-list
function openmira_validate_php_write_or_rollback(
    string $resolved,
    ?string $old_content,
    ?array $backup,
    string $ability,
    string $operation,
    float $started_at,
): bool|WP_Error {
    if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'php') {
        return true;
    }

    $syntax = openmira_php_lint_file($resolved);
    if ($syntax['ok'] === true) {
        return true;
    }

    $rolled_back = openmira_rollback_failed_php_write($resolved, $old_content);
    $backup_id = is_array($backup) ? (string) ($backup['id'] ?? '') : '';
    openmira_record_audit_event([
        'ability' => $ability,
        'operation' => $operation,
        'target_path' => openmira_display_path($resolved),
        'status' => 'error',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'error' => 'php_syntax_failed',
        'backup_id' => $backup_id,
    ]);

    return new WP_Error(
        'openmira_php_syntax_failed',
        sprintf(
            'PHP syntax check failed for %s. The write was %s.',
            openmira_display_path($resolved),
            $rolled_back ? 'rolled back' : 'not fully rolled back',
        ),
        [
            'path' => openmira_display_path($resolved),
            'rolled_back' => $rolled_back,
            'backup_id' => $backup_id,
            'syntax' => $syntax,
        ],
    );
}

/**
 * Run php -l for one PHP file.
 *
 * @return array{ok: bool, exit_code: int, output: string, command: string}
 */
function openmira_php_lint_file(string $resolved): array
{
    if (class_exists('PhpParser\\ParserFactory')) {
        $content = file_get_contents($resolved);
        if (!is_string($content)) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'output' => 'Could not read PHP file for syntax validation.',
                'command' => 'PhpParser',
            ];
        }

        try {
            (new PhpParser\ParserFactory())
                ->createForHostVersion()
                ->parse($content);

            return [
                'ok' => true,
                'exit_code' => 0,
                'output' => 'No syntax errors detected.',
                'command' => 'PhpParser',
            ];
        } catch (Throwable $throwable) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'output' => $throwable->getMessage(),
                'command' => 'PhpParser',
            ];
        }
    }

    // PHP_BINARY can be empty in WordPress Playground/WASM-style runtimes.
    // @mago-expect analysis:redundant-comparison
    // @mago-expect analysis:redundant-condition
    $php_binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $command = escapeshellarg($php_binary) . ' -l ' . escapeshellarg($resolved);
    $output = [];
    $exit_code = 1;
    exec(command: $command . ' 2>&1', output: $output, result_code: $exit_code);

    return [
        'ok' => $exit_code === 0,
        'exit_code' => $exit_code,
        'output' => implode("\n", $output),
        'command' => $command,
    ];
}

/**
 * Restore the previous PHP file state after a failed syntax check.
 */
function openmira_rollback_failed_php_write(string $resolved, ?string $old_content): bool
{
    if ($old_content === null) {
        return !is_file($resolved) || unlink($resolved);
    }

    return file_put_contents($resolved, $old_content, LOCK_EX) !== false;
}

/**
 * Return a per-user read-tracking transient key.
 */
function openmira_read_tracking_key(): string
{
    return 'openmira_file_reads_' . get_current_user_id();
}

/**
 * Return normalized read-tracking state.
 *
 * @return array<string, array<array-key, mixed>>
 */
function openmira_get_read_tracking_state(): array
{
    // @mago-expect analysis:mixed-assignment
    $state = get_transient(openmira_read_tracking_key());
    if (!is_array($state)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($state as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }
        $normalized[$key] = $entry;
    }

    return $normalized;
}

/**
 * Create a ring-buffer backup before a destructive file operation.
 *
 * @return array<string, mixed>|null
 */
function openmira_create_file_backup(string $resolved, string $operation): ?array
{
    if (!is_file($resolved) || !is_readable($resolved)) {
        return null;
    }

    if (!wp_mkdir_p(OPENMIRA_FILE_BACKUPS_DIR)) {
        return null;
    }

    $path_hash = openmira_hash_file_path($resolved);
    $target_dir = OPENMIRA_FILE_BACKUPS_DIR . '/' . $path_hash;
    if (!wp_mkdir_p($target_dir)) {
        return null;
    }

    $id = gmdate('YmdHis') . '-' . (string) wp_generate_uuid4();
    $backup_path = $target_dir . '/' . $id . '.bak';
    if (!copy($resolved, $backup_path)) {
        return null;
    }

    $content_hash = hash_file(algo: 'sha256', filename: $resolved);
    $entry = [
        'id' => $id,
        'operation' => $operation,
        'target_path' => openmira_display_path($resolved),
        'backup_path' => openmira_display_path($backup_path),
        'content_hash' => is_string($content_hash) ? $content_hash : '',
        'size' => (int) filesize($resolved),
        'created_at' => gmdate('c'),
        'created_by' => get_current_user_id(),
    ];

    $index = openmira_get_file_backup_index();
    $entries = openmira_normalize_list($index[$path_hash] ?? []);
    array_unshift($entries, $entry);
    $removed = array_slice($entries, offset: OPENMIRA_FILE_BACKUPS_MAX);
    foreach ($removed as $removed_entry) {
        $removed_path = openmira_backup_absolute_path((string) ($removed_entry['backup_path'] ?? ''));
        if ($removed_path !== '' && is_file($removed_path)) {
            unlink($removed_path);
        }
    }
    $index[$path_hash] = array_slice($entries, offset: 0, length: OPENMIRA_FILE_BACKUPS_MAX);
    update_option(OPENMIRA_FILE_BACKUPS_OPTION, $index, autoload: false);

    return $entry;
}

/**
 * Return file backup index.
 *
 * @return array<string, list<array<array-key, mixed>>>
 */
function openmira_get_file_backup_index(): array
{
    // @mago-expect analysis:mixed-assignment
    $index = get_option(OPENMIRA_FILE_BACKUPS_OPTION, default_value: []);
    if (!is_array($index)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($index as $key => $entries) {
        if (!is_string($key)) {
            continue;
        }
        $normalized[$key] = openmira_normalize_list($entries);
    }

    return $normalized;
}

/**
 * Persist file backup index.
 *
 * @param array<string, list<array<array-key, mixed>>> $index
 */
function openmira_update_file_backup_index(array $index): void
{
    update_option(OPENMIRA_FILE_BACKUPS_OPTION, $index, autoload: false);
}

/**
 * Return backups, optionally filtered by target path.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_list_file_backups(?string $target_path = null): array
{
    $index = openmira_get_file_backup_index();
    if ($target_path !== null && $target_path !== '') {
        $resolved = openmira_resolve_path(path: $target_path, must_exist: false);
        if (!is_string($resolved)) {
            return [];
        }

        return openmira_normalize_list($index[openmira_hash_file_path($resolved)] ?? []);
    }

    $backups = [];
    foreach ($index as $entries) {
        foreach ($entries as $entry) {
            $backups[] = $entry;
        }
    }

    usort($backups, static fn(array $first, array $second): int => strcmp(
        (string) ($second['created_at'] ?? ''),
        (string) ($first['created_at'] ?? ''),
    ));

    return $backups;
}

/**
 * Find a backup by ID.
 *
 * @return array{entry: array<array-key, mixed>, path_hash: string}|null
 */
function openmira_find_file_backup(string $backup_id): ?array
{
    if ($backup_id === '') {
        return null;
    }

    foreach (openmira_get_file_backup_index() as $path_hash => $entries) {
        foreach ($entries as $entry) {
            if ((string) ($entry['id'] ?? '') !== $backup_id) {
                continue;
            }

            return [
                'entry' => $entry,
                'path_hash' => $path_hash,
            ];
        }
    }

    return null;
}

/**
 * Convert stored backup path back to absolute path.
 */
function openmira_backup_absolute_path(string $path): string
{
    if ($path === '') {
        return '';
    }

    if (str_starts_with($path, 'wp-content/')) {
        return WP_CONTENT_DIR . substr($path, strlen('wp-content'));
    }

    $resolved = openmira_resolve_path(path: $path, must_exist: false);

    return is_string($resolved) ? $resolved : '';
}

/**
 * Record a compact audit event.
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function openmira_record_audit_event(array $event): array
{
    $entry = array_merge([
        'id' => (string) wp_generate_uuid4(),
        'created_at' => gmdate('c'),
        'created_by' => get_current_user_id(),
        'ability' => '',
        'operation' => '',
        'target_path' => '',
        'status' => 'success',
        'duration_ms' => 0,
        'error' => '',
        'diff_summary' => '',
        'diff' => '',
        'backup_id' => '',
    ], $event);
    $entry['diff'] = openmira_compact_audit_diff($entry['diff']);

    $log = openmira_get_audit_log();
    array_unshift($log, $entry);
    $log = array_slice($log, offset: 0, length: OPENMIRA_AUDIT_LOG_MAX);
    update_option(OPENMIRA_AUDIT_LOG_OPTION, $log, autoload: false);

    return $entry;
}

/**
 * Return normalized audit log.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_get_audit_log(): array
{
    return openmira_normalize_list(get_option(OPENMIRA_AUDIT_LOG_OPTION, default_value: []));
}

/**
 * Bound stored audit diffs so the audit option remains inspectable.
 */
function openmira_compact_audit_diff($diff): string
{
    if (!is_string($diff) || $diff === '') {
        return '';
    }

    if (strlen($diff) <= OPENMIRA_AUDIT_DIFF_MAX_BYTES) {
        return $diff;
    }

    return substr($diff, offset: 0, length: OPENMIRA_AUDIT_DIFF_MAX_BYTES) . "\n@@ audit diff truncated @@\n";
}

/**
 * Join multiple file diffs into one bounded audit diff.
 *
 * @param list<array<array-key, mixed>> $files
 */
function openmira_join_file_diffs_for_audit(array $files): string
{
    $chunks = [];
    foreach ($files as $file) {
        if (!array_key_exists('diff', $file) || !is_string($file['diff']) || $file['diff'] === '') {
            continue;
        }
        $diff = $file['diff'];
        $path = array_key_exists('path', $file) && is_string($file['path']) ? $file['path'] : '';
        $chunks[] = $path !== '' ? "# {$path}\n{$diff}" : $diff;
    }

    return openmira_compact_audit_diff(implode("\n", $chunks));
}

/**
 * Clear audit log.
 */
function openmira_clear_audit_log(): void
{
    update_option(OPENMIRA_AUDIT_LOG_OPTION, [], autoload: false);
}

/**
 * Return whether destructive abilities require explicit Act mode.
 */
function openmira_is_plan_act_required(): bool
{
    return get_option(OPENMIRA_PLAN_ACT_REQUIRED_OPTION, default_value: '1') === '1';
}

/**
 * Configure Plan/Act enforcement.
 */
// @mago-expect lint:no-boolean-flag-parameter
function openmira_set_plan_act_required(bool $required): void
{
    update_option(OPENMIRA_PLAN_ACT_REQUIRED_OPTION, $required ? '1' : '0', autoload: false);
}

/**
 * Return current user's safety mode.
 */
function openmira_get_safety_mode(): string
{
    if (!openmira_is_plan_act_required()) {
        return 'act';
    }

    // @mago-expect analysis:mixed-assignment
    $mode = get_transient(openmira_safety_mode_key());

    return $mode === 'act' ? 'act' : 'plan';
}

/**
 * Set current user's safety mode.
 */
function openmira_set_safety_mode(string $mode, int $ttl_minutes): string
{
    if ($mode !== 'act') {
        delete_transient(openmira_safety_mode_key());
        return 'plan';
    }

    $ttl = max(OPENMIRA_MINUTE_IN_SECONDS, min(OPENMIRA_DAY_IN_SECONDS, $ttl_minutes * OPENMIRA_MINUTE_IN_SECONDS));
    set_transient(transient: openmira_safety_mode_key(), value: 'act', expiration: $ttl);

    return 'act';
}

/**
 * Return current user's safety-mode transient key.
 */
function openmira_safety_mode_key(): string
{
    return 'openmira_safety_mode_' . get_current_user_id();
}

/**
 * Require Act mode for destructive operations when Plan/Act enforcement is enabled.
 *
 * @return true|WP_Error
 */
function openmira_require_act_mode(string $ability): bool|WP_Error
{
    if (!openmira_is_plan_act_required()) {
        return true;
    }

    if (openmira_get_safety_mode() === 'act') {
        return true;
    }

    return new WP_Error('openmira_plan_mode', sprintf(
        'Open Mira is in Plan mode. Call openmira/set-safety-mode with mode=act before running %s.',
        $ability,
    ));
}

/**
 * Return a short diff summary.
 */
function openmira_diff_summary(string $diff): string
{
    if ($diff === '') {
        return '';
    }

    $added_matches = [];
    $removed_matches = [];
    $added = preg_match_all(pattern: '/^\\+(?!\\+\\+)/m', subject: $diff, matches: $added_matches);
    $removed = preg_match_all(pattern: '/^-(?!--)/m', subject: $diff, matches: $removed_matches);

    return sprintf('+%d -%d', is_int($added) ? $added : 0, is_int($removed) ? $removed : 0);
}

/**
 * Build a compact unified diff for before/after content.
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_build_unified_diff(?string $old_content, ?string $new_content, string $path): string
{
    if ($old_content === $new_content) {
        return '';
    }

    if (!openmira_is_text_diffable($old_content) || !openmira_is_text_diffable($new_content)) {
        return sprintf(
            "--- %s\n+++ %s\n@@ binary content changed @@\n",
            openmira_display_path($path) . '.before',
            openmira_display_path($path) . '.after',
        );
    }

    $old_lines = $old_content === null ? [] : openmira_split_diff_lines($old_content);
    $new_lines = $new_content === null ? [] : openmira_split_diff_lines($new_content);

    $prefix = 0;
    $old_count = count($old_lines);
    $new_count = count($new_lines);
    while ($prefix < $old_count && $prefix < $new_count && $old_lines[$prefix] === $new_lines[$prefix]) {
        $prefix++;
    }

    $suffix = 0;
    while (
        $suffix < ($old_count - $prefix)
        && $suffix < ($new_count - $prefix)
        && $old_lines[$old_count - 1 - $suffix] === $new_lines[$new_count - 1 - $suffix]
    ) {
        $suffix++;
    }

    $context = 3;
    $old_start_index = max(0, $prefix - $context);
    $new_start_index = max(0, $prefix - $context);
    $old_end_index = min($old_count, $old_count - $suffix + $context);
    $new_end_index = min($new_count, $new_count - $suffix + $context);

    $old_hunk = array_slice($old_lines, offset: $old_start_index, length: $old_end_index - $old_start_index);
    $new_hunk = array_slice($new_lines, offset: $new_start_index, length: $new_end_index - $new_start_index);

    $old_header_count = max(1, count($old_hunk));
    $new_header_count = max(1, count($new_hunk));
    $diff = sprintf(
        "--- %s\n+++ %s\n@@ -%d,%d +%d,%d @@\n",
        openmira_display_path($path) . '.before',
        openmira_display_path($path) . '.after',
        $old_start_index + 1,
        $old_header_count,
        $new_start_index + 1,
        $new_header_count,
    );

    $prefix_context = min($context, $prefix);
    for ($index = 0; $index < $prefix_context; $index++) {
        $line = $old_lines[$old_start_index + $index] ?? '';
        $diff .= ' ' . $line;
    }

    $old_changed = array_slice($old_lines, offset: $prefix, length: $old_count - $prefix - $suffix);
    $new_changed = array_slice($new_lines, offset: $prefix, length: $new_count - $prefix - $suffix);
    $max_changed_lines = 500;
    if ((count($old_changed) + count($new_changed)) > $max_changed_lines) {
        return sprintf(
            "--- %s\n+++ %s\n@@ large diff omitted @@\n-old_hash:%s\n+new_hash:%s\n",
            openmira_display_path($path) . '.before',
            openmira_display_path($path) . '.after',
            $old_content === null ? '' : openmira_file_hash_content($old_content),
            $new_content === null ? '' : openmira_file_hash_content($new_content),
        );
    }

    foreach ($old_changed as $line) {
        $diff .= '-' . $line;
    }
    foreach ($new_changed as $line) {
        $diff .= '+' . $line;
    }

    $suffix_context = min($context, $suffix);
    for ($index = $suffix_context; $index > 0; $index--) {
        $line = $old_lines[$old_count - $index] ?? '';
        $diff .= ' ' . $line;
    }

    return $diff;
}

/**
 * Return whether content can be represented in a text diff.
 */
function openmira_is_text_diffable(?string $content): bool
{
    if ($content === null) {
        return true;
    }

    return !str_contains($content, "\0") && mb_check_encoding(value: $content, encoding: 'UTF-8');
}

/**
 * Split text into diff lines while preserving line endings.
 *
 * @return list<string>
 */
function openmira_split_diff_lines(string $content): array
{
    if ($content === '') {
        return [];
    }

    $lines = preg_split(pattern: '/(?<=\\n)/', subject: $content);
    if (!is_array($lines)) {
        return [$content];
    }

    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
}

/**
 * Normalize mixed values into a list of arrays.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_normalize_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $items = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = $item;
    }

    return $items;
}
