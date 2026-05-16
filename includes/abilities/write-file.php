<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Write/create files.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/write-file', [
    'label' => __('Write File', domain: 'open-mira'),
    'description' => __(
        'Writes content to a file on the server filesystem. Existing files require read-file first, or expected_current_hash from a recent read/scaffold response. PHP files (*.php) can ONLY be written to wp-content/openmira-sandbox/.',
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
            'content' => [
                'type' => 'string',
                'description' => 'Content to write to the file.',
            ],
            'encoding' => [
                'type' => 'string',
                'description' => 'Content encoding.',
                'enum' => ['utf-8', 'base64'],
                'default' => 'utf-8',
            ],
            'mode' => [
                'type' => 'string',
                'description' => 'Write mode.',
                'enum' => ['overwrite', 'append'],
                'default' => 'overwrite',
            ],
            'create_directories' => [
                'type' => 'boolean',
                'description' => 'Whether to create parent directories if they do not exist.',
                'default' => true,
            ],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash for the existing file. Use content_hash from read-file or scaffold outputs to replace an explicit read-file call.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
        ],
        'required' => ['path', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the written file.'],
            'bytes_written' => ['type' => 'integer', 'description' => 'Number of bytes written.'],
            'created' => [
                'type' => 'boolean',
                'description' => 'Whether a new file was created (vs overwriting existing).',
            ],
            'directories_created' => [
                'type' => 'array',
                'description' => 'List of directories that were created.',
                'items' => ['type' => 'string'],
            ],
            'size' => ['type' => 'integer', 'description' => 'Final file size in bytes.'],
            'previous_hash' => ['type' => 'string', 'description' => 'SHA-256 hash before the write, if any.'],
            'content_hash' => ['type' => 'string', 'description' => 'SHA-256 hash after the write.'],
            'diff' => ['type' => 'string', 'description' => 'Unified diff preview of the write.'],
            'backup' => ['type' => 'object', 'description' => 'Backup metadata when an existing file was changed.'],
            'audit' => ['type' => 'object', 'description' => 'Audit event metadata.'],
        ],
    ],
    'execute_callback' => 'openmira_write_file',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'PHP FILE SANDBOX:',
                '- PHP files (*.php) can ONLY be written to: wp-content/openmira-sandbox/',
                '- Use a path like "wp-content/openmira-sandbox/my-feature.php"',
                '- Non-PHP files can be written anywhere under ABSPATH.',
                '- Sandbox plugins are loaded by a mu-plugin loader on every request.',
                '- Existing files require read-file first, unless you pass expected_current_hash from a recent read/scaffold response.',
                '- Scaffold abilities return content_hash per file; pass that as expected_current_hash for immediate follow-up writes.',
                '- For theme.json design-system changes, prefer openmira/apply-patch with the bulk paths form instead of overwriting the whole file.',
                '',
                'CRASH RECOVERY:',
                '- If a sandbox plugin causes a fatal error, the loader auto-detects the crash',
                '  and enters safe mode on the next request. All sandbox plugins are skipped.',
                '- In safe mode, MCP still works. You can read, fix, or delete the broken file.',
                '- After fixing, delete the file "wp-content/openmira-sandbox/.crashed"',
                '  to exit safe mode and resume loading sandbox plugins.',
                '- If MCP suddenly stops responding after you wrote a PHP file, wait — the next',
                '  request will auto-recover into safe mode and MCP will be available again.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Decode write content based on encoding.
 *
 * @param string $content  Raw content string.
 * @param string $encoding Encoding type ('utf-8' or 'base64').
 * @return string|WP_Error Decoded content or WP_Error on failure.
 */
function openmira_decode_write_content(string $content, string $encoding): string|WP_Error
{
    if ($encoding === 'base64') {
        $decoded = base64_decode(string: $content, strict: true);
        if ($decoded === false) {
            return new WP_Error('invalid_base64', 'The provided content is not valid base64.');
        }

        return $decoded;
    }

    return $content;
}

/**
 * Write content to a file.
 *
 * @param array $input Input with 'path', 'content', optional 'encoding', 'mode', 'create_directories'.
 * @return array|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_write_file($input)
{
    $mode_error = openmira_require_act_mode('openmira/write-file');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $resolved = openmira_resolve_path(path: (string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $encoding = (string) ($input['encoding'] ?? 'utf-8');
    $mode = (string) ($input['mode'] ?? 'overwrite');
    $create_directories = ($input['create_directories'] ?? true) !== false;
    $is_php = strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'php';

    if ($is_php) {
        $sandbox_error = openmira_check_php_sandbox($resolved);
        if (is_wp_error($sandbox_error)) {
            return $sandbox_error;
        }
    }

    $content = openmira_decode_write_content((string) $input['content'], $encoding);
    if (is_wp_error($content)) {
        return $content;
    }

    $created = !file_exists($resolved);
    $old_content = is_file($resolved) ? file_get_contents($resolved) : null;
    if ($old_content === false) {
        return new WP_Error('read_failed', sprintf('Could not read existing file before write: %s', $resolved));
    }
    if (!$created) {
        $fresh_read = openmira_require_fresh_file_read(
            resolved: $resolved,
            ability: 'openmira/write-file',
            expected_hash: (string) ($input['expected_current_hash'] ?? ''),
        );
        if (is_wp_error($fresh_read)) {
            return $fresh_read;
        }
    }
    $previous_hash = is_string($old_content) ? openmira_file_hash_content($old_content) : '';
    $parent_dir = dirname($resolved);

    if (!is_dir($parent_dir) && !$create_directories) {
        return new WP_Error('directory_not_found', sprintf('Parent directory does not exist: %s', $parent_dir));
    }

    $directories_created = openmira_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return $directories_created;
    }

    $new_content = $mode === 'append' && is_string($old_content) ? $old_content . $content : $content;
    $backup = $created ? null : openmira_create_file_backup($resolved, operation: 'write-file');

    $bytes_written = file_put_contents($resolved, $new_content, LOCK_EX);
    if ($bytes_written === false) {
        openmira_record_audit_event([
            'ability' => 'openmira/write-file',
            'operation' => $mode,
            'target_path' => openmira_display_path($resolved),
            'status' => 'error',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'error' => 'write_failed',
            'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
        ]);
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }

    if ($created) {
        chmod(filename: $resolved, permissions: 0644);
    }

    $syntax_check = openmira_validate_php_write_or_rollback(
        resolved: $resolved,
        old_content: $old_content,
        backup: $backup,
        ability: 'openmira/write-file',
        operation: $mode,
        started_at: $started_at,
    );
    if (is_wp_error($syntax_check)) {
        return $syntax_check;
    }

    $diff = openmira_build_unified_diff($old_content, $new_content, $resolved);
    $audit = openmira_record_audit_event([
        'ability' => 'openmira/write-file',
        'operation' => $mode,
        'target_path' => openmira_display_path($resolved),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => openmira_diff_summary($diff),
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    $result = [
        'path' => $resolved,
        'bytes_written' => $bytes_written,
        'created' => $created,
        'directories_created' => $directories_created,
        'size' => filesize($resolved),
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
