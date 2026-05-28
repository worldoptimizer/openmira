<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Delete files and directories.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/delete-file', [
    'label' => __('Delete File', domain: 'open-mira'),
    'description' => __(
        'Deletes a file or directory from the server filesystem. Non-empty directories require the recursive flag. Critical WordPress directories (ABSPATH root, wp-admin, wp-includes) are protected from deletion. Idempotent: deleting a non-existent path succeeds with deleted=false.',
        domain: 'open-mira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the file or directory to delete. Relative paths are resolved from ABSPATH.',
                'minLength' => 1,
            ],
            'recursive' => [
                'type' => 'boolean',
                'description' => 'Whether to recursively delete directory contents. Required for non-empty directories.',
                'default' => false,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path that was targeted.'],
            'type' => [
                'type' => 'string',
                'description' => 'Type of the target: "file", "directory", or "not_found".',
            ],
            'deleted' => ['type' => 'boolean', 'description' => 'Whether anything was actually deleted.'],
            'items_deleted' => ['type' => 'integer', 'description' => 'Number of files/directories deleted.'],
            'previous_hash' => ['type' => 'string', 'description' => 'SHA-256 hash before deletion, for files.'],
            'diff' => ['type' => 'string', 'description' => 'Unified diff preview for deleted file content.'],
            'backup' => ['type' => 'object', 'description' => 'Backup metadata for the deleted file.'],
            'audit' => ['type' => 'object', 'description' => 'Audit event metadata.'],
        ],
    ],
    'execute_callback' => 'openmira_delete_file',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SANDBOX NOTES:',
                '- Files in wp-content/openmira-sandbox/ (the PHP sandbox) can be deleted.',
                '- To exit safe mode after a crash, delete: wp-content/openmira-sandbox/.crashed',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Delete a file or directory.
 *
 * @param array $input Input with 'path', optional 'recursive'.
 * @return array|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_delete_file($input)
{
    $mode_error = openmira_require_act_mode('openmira/delete-file');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $resolved = openmira_resolve_path((string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }
    $symlink_error = openmira_reject_final_path_symlink($resolved);
    if (is_wp_error($symlink_error)) {
        return $symlink_error;
    }

    $recursive = ($input['recursive'] ?? false) === true;

    // Idempotent: non-existent path is a no-op success.
    if (!file_exists($resolved) && !is_link($resolved)) {
        return [
            'path' => $resolved,
            'type' => 'not_found',
            'deleted' => false,
            'items_deleted' => 0,
        ];
    }

    // Protect critical WordPress directories.
    $real_resolved = realpath($resolved);
    $protected = array_filter([
        realpath(ABSPATH),
        realpath(ABSPATH . 'wp-admin'),
        realpath(ABSPATH . 'wp-includes'),
        realpath(WP_CONTENT_DIR . '/mu-plugins'),
    ]);

    if (in_array($real_resolved, $protected, strict: true)) {
        return new WP_Error('protected_path', sprintf('Cannot delete protected WordPress directory: %s', $resolved));
    }

    // Delete a file or symlink.
    if (is_file($resolved) || is_link($resolved)) {
        if (is_file($resolved)) {
            $fresh_read = openmira_require_fresh_file_read(resolved: $resolved, ability: 'openmira/delete-file');
            if (is_wp_error($fresh_read)) {
                return $fresh_read;
            }
        }
        $old_content = is_file($resolved) && is_readable($resolved) ? file_get_contents($resolved) : null;
        if ($old_content === false) {
            return new WP_Error('read_failed', sprintf('Could not read file before delete: %s', $resolved));
        }
        $backup = openmira_create_file_backup($resolved, operation: 'delete-file');
        if (!unlink($resolved)) {
            openmira_record_audit_event([
                'ability' => 'openmira/delete-file',
                'operation' => 'delete_file',
                'target_path' => openmira_display_path($resolved),
                'status' => 'error',
                'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
                'error' => 'delete_failed',
                'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
            ]);
            return new WP_Error('delete_failed', sprintf('Failed to delete file: %s', $resolved));
        }
        $diff = openmira_build_unified_diff(old_content: $old_content, new_content: null, path: $resolved);
        $audit = openmira_record_audit_event([
            'ability' => 'openmira/delete-file',
            'operation' => 'delete_file',
            'target_path' => openmira_display_path($resolved),
            'status' => 'success',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'diff_summary' => openmira_diff_summary($diff),
            'diff' => $diff,
            'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
        ]);
        $result = [
            'path' => $resolved,
            'type' => 'file',
            'deleted' => true,
            'items_deleted' => 1,
            'previous_hash' => is_string($old_content) ? openmira_file_hash_content($old_content) : '',
            'diff' => $diff,
            'audit' => $audit,
        ];
        if (is_array($backup)) {
            $result['backup'] = $backup;
        }

        return $result;
    }

    // Delete a directory.
    if (is_dir($resolved)) {
        $result = openmira_delete_directory($resolved, $recursive);
        if (is_wp_error($result)) {
            return $result;
        }
        $result['audit'] = openmira_record_audit_event([
            'ability' => 'openmira/delete-file',
            'operation' => $recursive ? 'delete_directory_recursive' : 'delete_directory',
            'target_path' => openmira_display_path($resolved),
            'status' => 'success',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'diff_summary' => '',
        ]);

        return $result;
    }

    return new WP_Error('unknown_type', sprintf('Path is not a file or directory: %s', $resolved));
}

/**
 * Delete a directory, optionally recursively.
 *
 * @param string $resolved  Absolute path to the directory.
 * @param bool   $recursive Whether to delete contents recursively.
 * @return array|WP_Error
 */
function openmira_delete_directory($resolved, $recursive)
{
    $contents = scandir($resolved);
    if ($contents === false) {
        return new WP_Error('scan_failed', sprintf('Could not read directory: %s', $resolved));
    }
    $is_empty = count($contents) <= 2;

    if (!$is_empty && !$recursive) {
        return new WP_Error('directory_not_empty', sprintf(
            'Directory is not empty. Set recursive=true to delete: %s',
            $resolved,
        ));
    }

    if ($is_empty) {
        if (!rmdir($resolved)) {
            return new WP_Error('delete_failed', sprintf('Failed to delete directory: %s', $resolved));
        }
        return [
            'path' => $resolved,
            'type' => 'directory',
            'deleted' => true,
            'items_deleted' => 1,
        ];
    }

    // Recursive delete: remove contents depth-first, then the directory itself.
    $items_deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $item_path = $item->getPathname();
        $symlink_error = openmira_reject_final_path_symlink($item_path);
        if (is_wp_error($symlink_error)) {
            return $symlink_error;
        }
        $deleted = $item->isDir() ? rmdir($item_path) : unlink($item_path);
        if (!$deleted) {
            return new WP_Error('delete_failed', sprintf('Failed to delete: %s', $item_path));
        }
        $items_deleted++;
    }

    if (!rmdir($resolved)) {
        return new WP_Error('delete_failed', sprintf('Failed to delete directory: %s', $resolved));
    }
    $items_deleted++;

    return [
        'path' => $resolved,
        'type' => 'directory',
        'deleted' => true,
        'items_deleted' => $items_deleted,
    ];
}
