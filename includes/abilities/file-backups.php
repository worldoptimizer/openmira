<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: File backups, restore, and audit log.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/list-file-backups', [
    'label' => __('List File Backups', domain: 'open-mira'),
    'description' => __(
        'Lists ring-buffer backups created by Open Mira file write/edit/delete operations.',
        domain: 'open-mira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Optional file path to filter backups.',
                'default' => '',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of backups to return.',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 200,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'backups' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['backups', 'count'],
    ],
    'execute_callback' => 'openmira_list_file_backups_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use before restore-file-backup to find backup IDs.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/restore-file-backup', [
    'label' => __('Restore File Backup', domain: 'open-mira'),
    'description' => __(
        'Restores a file from an Open Mira file backup. Creates a fresh pre-restore backup of the current target file when it exists.',
        domain: 'open-mira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'backup_id' => [
                'type' => 'string',
                'description' => 'Backup ID returned by list-file-backups or a prior write/edit/delete result.',
                'minLength' => 1,
            ],
        ],
        'required' => ['backup_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'backup' => ['type' => 'object'],
            'pre_restore_backup' => ['type' => 'object'],
            'previous_hash' => ['type' => 'string'],
            'content_hash' => ['type' => 'string'],
            'diff' => ['type' => 'string'],
            'audit' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'openmira_restore_file_backup_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Destructive restore. Inspect the returned diff and pre_restore_backup.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/read-audit-log', [
    'label' => __('Read Audit Log', domain: 'open-mira'),
    'description' => __('Reads recent Open Mira audit events for file safety operations.', domain: 'open-mira'),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of events to return.',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 200,
            ],
            'destructive_only' => [
                'type' => 'boolean',
                'description' => 'Return only write/edit/delete/restore events.',
                'default' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'events' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['events', 'count'],
    ],
    'execute_callback' => 'openmira_read_audit_log_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use to inspect recent file writes, edits, deletes, restores, and failures.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List file backups ability callback.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_list_file_backups_ability(array $input): array
{
    $path = is_string($input['path'] ?? null) ? $input['path'] : '';
    $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
    $backups = array_slice(openmira_list_file_backups($path), offset: 0, length: $limit);

    return [
        'backups' => $backups,
        'count' => count($backups),
    ];
}

/**
 * Restore file backup ability callback.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_restore_file_backup_ability(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/restore-file-backup');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $backup_id = (string) ($input['backup_id'] ?? '');
    $found = openmira_find_file_backup($backup_id);
    if ($found === null) {
        return new WP_Error('backup_not_found', 'File backup not found.');
    }

    $backup = $found['entry'];
    $target_path = (string) ($backup['target_path'] ?? '');
    $backup_path = openmira_backup_absolute_path((string) ($backup['backup_path'] ?? ''));
    $resolved = openmira_resolve_path(path: $target_path, must_exist: false);
    if (!is_string($resolved)) {
        return $resolved;
    }
    $symlink_error = openmira_reject_final_path_symlink($resolved);
    if (is_wp_error($symlink_error)) {
        return $symlink_error;
    }
    if ($backup_path === '' || !is_file($backup_path) || !is_readable($backup_path)) {
        return new WP_Error('backup_payload_missing', 'Backup payload file is missing or unreadable.');
    }

    $parent_dir = dirname($resolved);
    $directories_created = openmira_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return $directories_created;
    }

    $old_content = is_file($resolved) ? file_get_contents($resolved) : null;
    if ($old_content === false) {
        return new WP_Error('read_failed', sprintf('Could not read current file before restore: %s', $resolved));
    }
    $new_content = file_get_contents($backup_path);
    if ($new_content === false) {
        return new WP_Error('backup_read_failed', sprintf('Could not read backup file: %s', $backup_path));
    }

    $pre_restore_backup = is_file($resolved)
        ? openmira_create_file_backup($resolved, operation: 'restore-file-backup')
        : null;
    if (!copy($backup_path, $resolved)) {
        openmira_record_audit_event([
            'ability' => 'openmira/restore-file-backup',
            'operation' => 'restore',
            'target_path' => openmira_display_path($resolved),
            'status' => 'error',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'error' => 'restore_failed',
            'backup_id' => $backup_id,
        ]);
        return new WP_Error('restore_failed', sprintf('Failed to restore backup to: %s', $resolved));
    }

    chmod(filename: $resolved, permissions: 0644);

    $diff = openmira_build_unified_diff($old_content, $new_content, $resolved);
    $audit = openmira_record_audit_event([
        'ability' => 'openmira/restore-file-backup',
        'operation' => 'restore',
        'target_path' => openmira_display_path($resolved),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => openmira_diff_summary($diff),
        'diff' => $diff,
        'backup_id' => $backup_id,
    ]);

    $result = [
        'path' => $resolved,
        'backup' => $backup,
        'previous_hash' => is_string($old_content) ? openmira_file_hash_content($old_content) : '',
        'content_hash' => openmira_file_hash_content($new_content),
        'diff' => $diff,
        'audit' => $audit,
    ];
    if (is_array($pre_restore_backup)) {
        $result['pre_restore_backup'] = $pre_restore_backup;
    }

    return $result;
}

/**
 * Read audit log ability callback.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_read_audit_log_ability(array $input): array
{
    $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
    $destructive_only = ($input['destructive_only'] ?? false) === true;
    $events = openmira_get_audit_log();
    if ($destructive_only) {
        $events = array_values(array_filter($events, static fn(array $event): bool => in_array(
            (string) ($event['ability'] ?? ''),
            [
                'openmira/write-file',
                'openmira/edit-file',
                'openmira/delete-file',
                'openmira/restore-file-backup',
            ],
            strict: true,
        )));
    }

    $events = array_slice($events, offset: 0, length: $limit);

    return [
        'events' => $events,
        'count' => count($events),
    ];
}
