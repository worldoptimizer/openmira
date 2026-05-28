<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Edit files via string replacement.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/edit-file', [
    'label' => __('Edit File', domain: 'open-mira'),
    'description' => __(
        'Edits an existing file by replacing an exact string match with new content. Works like the edit tool in AI code agents: specify the old text to find and the new text to replace it with. The old string must be unique in the file unless replace_all is set.',
        domain: 'open-mira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'File path. Relative paths are resolved from the WordPress root (ABSPATH).',
                'minLength' => 1,
            ],
            'old_string' => [
                'type' => 'string',
                'description' => 'The exact text to find in the file. Must match exactly including whitespace and indentation.',
            ],
            'new_string' => [
                'type' => 'string',
                'description' => 'The text to replace old_string with. Use an empty string to delete the matched text.',
            ],
            'replace_all' => [
                'type' => 'boolean',
                'description' => 'Replace all occurrences of old_string. When false (default), old_string must appear exactly once in the file.',
                'default' => false,
            ],
        ],
        'required' => ['path', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the edited file.'],
            'replacements' => ['type' => 'integer', 'description' => 'Number of replacements made.'],
            'size' => ['type' => 'integer', 'description' => 'Final file size in bytes.'],
            'previous_hash' => ['type' => 'string', 'description' => 'SHA-256 hash before the edit.'],
            'content_hash' => ['type' => 'string', 'description' => 'SHA-256 hash after the edit.'],
            'diff' => ['type' => 'string', 'description' => 'Unified diff preview of the edit.'],
            'backup' => ['type' => 'object', 'description' => 'Backup metadata for the pre-edit file.'],
            'audit' => ['type' => 'object', 'description' => 'Audit event metadata.'],
        ],
    ],
    'execute_callback' => 'openmira_edit_file',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use this to make targeted edits to existing files. Provide the exact text to find (old_string)',
                'and the replacement text (new_string). The old_string must match the file content exactly,',
                'including whitespace and indentation.',
                '',
                'TIPS:',
                '- Read the file first to get the exact text to match.',
                '- old_string must be unique in the file (unless using replace_all).',
                '- Include enough surrounding context in old_string to make it unique.',
                '- old_string and new_string must be different.',
                '- To delete text, set new_string to an empty string.',
                '',
                'PHP SANDBOX: Same rules as write-file — PHP files can only be written to the sandbox directory.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Replace the first occurrence of a substring.
 *
 * Assumes $old_string exists in $content (caller must verify).
 *
 * @param string $content    Original file content.
 * @param string $old_string Text to find.
 * @param string $new_string Text to replace with.
 * @return string The new content.
 */
function openmira_edit_replace_first(string $content, string $old_string, string $new_string): string
{
    /** @var int $pos — caller guarantees $old_string exists in $content */
    $pos = strpos($content, $old_string);

    return (
        substr($content, offset: 0, length: $pos) . $new_string . substr($content, offset: $pos + strlen($old_string))
    );
}

/**
 * Edit a file by replacing an exact string match.
 *
 * @param array $input Input with 'path', 'old_string', 'new_string', optional 'replace_all'.
 * @return array|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_edit_file($input)
{
    $mode_error = openmira_require_act_mode('openmira/edit-file');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $resolved = openmira_resolve_path(path: (string) $input['path'], must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }
    $symlink_error = openmira_reject_final_path_symlink($resolved);
    if (is_wp_error($symlink_error)) {
        return $symlink_error;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved));
    }

    if (!is_readable($resolved) || !is_writable($resolved)) {
        return new WP_Error('not_writable', sprintf('File is not readable/writable: %s', $resolved));
    }

    $fresh_read = openmira_require_fresh_file_read(resolved: $resolved, ability: 'openmira/edit-file');
    if (is_wp_error($fresh_read)) {
        return $fresh_read;
    }

    $old_string = (string) $input['old_string'];
    $new_string = (string) $input['new_string'];
    $replace_all = ($input['replace_all'] ?? false) === true;

    if ($old_string === $new_string) {
        return new WP_Error('no_change', 'old_string and new_string are identical. No edit needed.');
    }

    $content = file_get_contents($resolved);
    if ($content === false) {
        return new WP_Error('read_failed', sprintf('Could not read file: %s', $resolved));
    }
    $previous_hash = openmira_file_hash_content($content);

    $count = substr_count($content, $old_string);

    if ($count === 0) {
        return new WP_Error(
            'no_match',
            'old_string was not found in the file. Make sure it matches the file content exactly, including whitespace and indentation.',
        );
    }

    if ($count > 1 && !$replace_all) {
        return new WP_Error('multiple_matches', sprintf(
            'old_string was found %d times in the file. Include more surrounding context to make it unique, or set replace_all to true.',
            $count,
        ));
    }

    $new_content = $replace_all
        ? str_replace($old_string, $new_string, $content)
        : openmira_edit_replace_first($content, $old_string, $new_string);

    $backup = openmira_create_file_backup($resolved, operation: 'edit-file');
    $bytes_written = file_put_contents($resolved, $new_content, LOCK_EX);
    if ($bytes_written === false) {
        openmira_record_audit_event([
            'ability' => 'openmira/edit-file',
            'operation' => $replace_all ? 'replace_all' : 'replace_first',
            'target_path' => openmira_display_path($resolved),
            'status' => 'error',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'error' => 'write_failed',
            'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
        ]);
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }

    $syntax_check = openmira_validate_php_write_or_rollback(
        resolved: $resolved,
        old_content: $content,
        backup: $backup,
        ability: 'openmira/edit-file',
        operation: $replace_all ? 'replace_all' : 'replace_first',
        started_at: $started_at,
    );
    if (is_wp_error($syntax_check)) {
        return $syntax_check;
    }

    $diff = openmira_build_unified_diff($content, $new_content, $resolved);
    $audit = openmira_record_audit_event([
        'ability' => 'openmira/edit-file',
        'operation' => $replace_all ? 'replace_all' : 'replace_first',
        'target_path' => openmira_display_path($resolved),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => openmira_diff_summary($diff),
        'diff' => $diff,
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    $result = [
        'path' => $resolved,
        'replacements' => $count,
        'size' => $bytes_written,
        'previous_hash' => $previous_hash,
        'content_hash' => openmira_file_hash_content($new_content),
        'diff' => $diff,
        'audit' => $audit,
    ];
    if (is_array($backup)) {
        $result['backup'] = $backup;
    }

    return $result;
}
