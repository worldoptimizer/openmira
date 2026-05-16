<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: Plan/Act safety mode.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/get-safety-mode', [
    'label' => __('Get Safety Mode', domain: 'open-mira'),
    'description' => __('Reads Open Mira Plan/Act enforcement state for the current user.', domain: 'open-mira'),
    'category' => 'filesystem',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'mode' => ['type' => 'string'],
            'plan_act_required' => ['type' => 'boolean'],
            'destructive_requires_act' => ['type' => 'boolean'],
        ],
        'required' => ['mode', 'plan_act_required', 'destructive_requires_act'],
    ],
    'execute_callback' => 'openmira_get_safety_mode_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Check this when a destructive ability is rejected in Plan mode.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/set-safety-mode', [
    'label' => __('Set Safety Mode', domain: 'open-mira'),
    'description' => __('Sets the current user safety mode to plan or temporary act.', domain: 'open-mira'),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'mode' => [
                'type' => 'string',
                'description' => 'Safety mode.',
                'enum' => ['plan', 'act'],
            ],
            'ttl_minutes' => [
                'type' => 'integer',
                'description' => 'How long Act mode should remain active.',
                'default' => 30,
                'minimum' => 1,
                'maximum' => 1440,
            ],
        ],
        'required' => ['mode'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'mode' => ['type' => 'string'],
            'plan_act_required' => ['type' => 'boolean'],
            'expires_in_minutes' => ['type' => 'integer'],
        ],
        'required' => ['mode', 'plan_act_required', 'expires_in_minutes'],
    ],
    'execute_callback' => 'openmira_set_safety_mode_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use mode=act only after the user has approved destructive changes.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Read safety mode callback.
 *
 * @return array<string, mixed>
 */
function openmira_get_safety_mode_ability(): array
{
    $required = openmira_is_plan_act_required();

    return [
        'mode' => openmira_get_safety_mode(),
        'plan_act_required' => $required,
        'destructive_requires_act' => $required,
    ];
}

/**
 * Set safety mode callback.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_set_safety_mode_ability(array $input): array
{
    $ttl_minutes = max(1, min(1440, (int) ($input['ttl_minutes'] ?? 30)));
    $mode = openmira_set_safety_mode((string) ($input['mode'] ?? 'plan'), $ttl_minutes);

    return [
        'mode' => $mode,
        'plan_act_required' => openmira_is_plan_act_required(),
        'expires_in_minutes' => $mode === 'act' ? $ttl_minutes : 0,
    ];
}
