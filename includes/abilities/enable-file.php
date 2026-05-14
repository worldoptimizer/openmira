<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Re-enable a disabled sandbox file by removing the .disabled suffix.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/enable-file', [
    'label' => __('Enable File', domain: 'open-mira'),
    'description' => __(
        'Re-enables a previously disabled sandbox file by removing the ".disabled" suffix from its filename. Only operates on files inside the sandbox directory (wp-content/openmira-sandbox/). Idempotent: enabling a file that is not disabled succeeds with enabled=false.',
        domain: 'open-mira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the .disabled file to re-enable. Can be the original filename (without .disabled suffix) or the full disabled filename. Relative paths are resolved from ABSPATH. Must be inside wp-content/openmira-sandbox/.',
                'minLength' => 1,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'disabled_path' => ['type' => 'string', 'description' => 'Absolute path of the disabled file.'],
            'enabled_path' => ['type' => 'string', 'description' => 'Absolute path after renaming.'],
            'enabled' => ['type' => 'boolean', 'description' => 'Whether the file was actually renamed.'],
        ],
    ],
    'execute_callback' => 'openmira_enable_file',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SANDBOX NOTES:',
                '- Only files inside wp-content/openmira-sandbox/ (the PHP sandbox) can be enabled.',
                '- Accepts either the original path or the .disabled path.',
                '- Counterpart to disable-file: removes the ".disabled" suffix so the loader picks the file up again.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Re-enable a disabled sandbox file by removing the .disabled suffix.
 *
 * @param array $input Input with 'path'.
 * @return array|WP_Error
 */
function openmira_enable_file($input)
{
    $path = (string) $input['path'];

    // Normalize: ensure we are looking for the .disabled version of the file.
    $disabled_path = openmira_is_disabled_file($path) ? $path : $path . '.disabled';

    $resolved = openmira_resolve_path($disabled_path, must_exist: true);

    // If the .disabled file was not found, check whether the original file already exists (idempotent).
    if (is_wp_error($resolved) && !openmira_is_disabled_file($path)) {
        $original_resolved = openmira_resolve_path($path, must_exist: true);
        if (!is_wp_error($original_resolved) && is_file($original_resolved)) {
            return [
                'disabled_path' => $original_resolved,
                'enabled_path' => $original_resolved,
                'enabled' => false,
            ];
        }
        return $resolved;
    }
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $sandbox_check = openmira_validate_sandbox_path($resolved);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved));
    }

    // Idempotent: file is not disabled.
    if (!openmira_is_disabled_file($resolved)) {
        return [
            'disabled_path' => $resolved,
            'enabled_path' => $resolved,
            'enabled' => false,
        ];
    }

    $enabled_path = substr($resolved, offset: 0, length: -9);

    if (file_exists($enabled_path)) {
        return new WP_Error('enabled_file_exists', sprintf('An enabled version already exists: %s', $enabled_path));
    }

    if (!rename($resolved, $enabled_path)) {
        return new WP_Error('rename_failed', sprintf('Failed to rename file: %s', $resolved));
    }

    return [
        'disabled_path' => $resolved,
        'enabled_path' => $enabled_path,
        'enabled' => true,
    ];
}
