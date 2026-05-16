<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: Project rules.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/read-project-rules', [
    'label' => __('Read Project Rules', domain: 'open-mira'),
    'description' => __(
        'Reads .openmirarules plus memory overlay so agents can follow project naming, coding, builder, and scaffold defaults.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean'],
            'path' => ['type' => 'string'],
            'format' => ['type' => 'string'],
            'parsed' => ['type' => 'object'],
            'raw' => ['type' => 'string'],
            'memory_overlay' => ['type' => 'object'],
            'candidates' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['found', 'path', 'format', 'parsed', 'raw', 'memory_overlay', 'candidates'],
    ],
    'execute_callback' => 'openmira_read_project_rules_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read before scaffolding themes, plugins, blocks, or patch operations.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/write-project-rules', [
    'label' => __('Write Project Rules', domain: 'open-mira'),
    'description' => __('Writes .openmirarules.json at the WordPress root.', domain: 'open-mira'),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'rules' => [
                'type' => 'object',
                'description' => 'Rules object to persist as JSON.',
            ],
            'merge_defaults' => [
                'type' => 'boolean',
                'description' => 'Merge supplied rules over Open Mira defaults.',
                'default' => true,
            ],
        ],
        'required' => ['rules'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'rules' => ['type' => 'object'],
            'diff' => ['type' => 'string'],
            'backup' => ['type' => 'object'],
            'audit' => ['type' => 'object'],
        ],
        'required' => ['path', 'rules', 'diff', 'audit'],
    ],
    'execute_callback' => 'openmira_write_project_rules_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use to persist project conventions. Requires Act mode when Plan/Act is enabled.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Read project rules callback.
 *
 * @return array<string, mixed>
 */
function openmira_read_project_rules_ability(): array
{
    return openmira_get_project_rules();
}

/**
 * Write project rules callback.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_write_project_rules_ability(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/write-project-rules');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $rules = is_array($input['rules'] ?? null) ? openmira_normalize_rules_array($input['rules']) : [];
    if ($rules === []) {
        return new WP_Error('empty_project_rules', 'rules must contain at least one key.');
    }
    if (($input['merge_defaults'] ?? true) !== false) {
        $rules = openmira_merge_project_rules(openmira_default_project_rules(), $rules);
    }

    $path = ABSPATH . OPENMIRA_RULES_PATH;
    $old_content = is_file($path) ? file_get_contents($path) : null;
    if ($old_content === false) {
        return new WP_Error('read_failed', sprintf(
            'Could not read existing rules file: %s',
            openmira_display_path($path),
        ));
    }

    $json = wp_json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return new WP_Error('json_encode_failed', 'Could not encode project rules as JSON.');
    }
    $new_content = $json . "\n";
    $backup = is_file($path) ? openmira_create_file_backup($path, operation: 'write-project-rules') : null;
    $written = file_put_contents($path, $new_content, LOCK_EX);
    if ($written === false) {
        return new WP_Error('write_failed', sprintf('Could not write project rules: %s', openmira_display_path($path)));
    }
    chmod(filename: $path, permissions: 0644);

    $diff = openmira_build_unified_diff($old_content, $new_content, $path);
    $audit = openmira_record_audit_event([
        'ability' => 'openmira/write-project-rules',
        'operation' => 'write_rules',
        'target_path' => openmira_display_path($path),
        'status' => 'success',
        'duration_ms' => 0,
        'diff_summary' => openmira_diff_summary($diff),
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    $result = [
        'path' => openmira_display_path($path),
        'rules' => $rules,
        'diff' => $diff,
        'audit' => $audit,
    ];
    if (is_array($backup)) {
        $result['backup'] = $backup;
    }

    return $result;
}
