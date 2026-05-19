<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: Open Mira skills.
 */

if (!defined('ABSPATH')) {
    exit();
}

openmira_register_skill_prompt_abilities();

wp_register_ability('openmira/list-skills', [
    'label' => __('List Skills', domain: 'open-mira'),
    'description' => __(
        'Lists installed Open Mira Skills without loading their full Markdown bodies.',
        domain: 'open-mira',
    ),
    'category' => 'skills',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'skills' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
            ],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['skills', 'count'],
    ],
    'execute_callback' => 'openmira_list_skills_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use to discover available workflow Skills before fetching a full SKILL.md body.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/get-skill', [
    'label' => __('Get Skill', domain: 'open-mira'),
    'description' => __('Returns the full Markdown body for one installed Open Mira Skill.', domain: 'open-mira'),
    'category' => 'skills',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'skill_id' => [
                'type' => 'string',
                'description' => 'Skill ID, e.g. wp-aware-editing.',
                'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$',
            ],
        ],
        'required' => ['skill_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'body' => ['type' => 'string'],
            'prompt_name' => ['type' => 'string'],
        ],
        'required' => ['id', 'title', 'description', 'body'],
    ],
    'execute_callback' => 'openmira_get_skill_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Fetch one Skill body when the user asks for a workflow that matches it.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List installed skills.
 *
 * @return array{skills: list<array{id: string, title: string, description: string, prompt_name: string}>, count: int}
 */
function openmira_list_skills_ability(): array
{
    $skills = [];
    foreach (openmira_get_skills() as $skill) {
        $skills[] = [
            'id' => $skill['id'],
            'title' => $skill['title'],
            'description' => $skill['description'],
            'prompt_name' => $skill['prompt_name'],
        ];
    }

    return ['skills' => $skills, 'count' => count($skills)];
}

/**
 * Return one installed skill body.
 *
 * @param array<string, mixed> $input
 * @return array{id: string, title: string, description: string, body: string, prompt_name: string}|WP_Error
 */
function openmira_get_skill_ability(array $input): array|WP_Error
{
    $skill_id = is_string($input['skill_id'] ?? null) ? sanitize_key($input['skill_id']) : '';
    $skill = $skill_id !== '' ? openmira_get_skill($skill_id) : null;
    if ($skill === null) {
        return new WP_Error('openmira_skill_not_found', sprintf('Skill not found: %s', $skill_id));
    }

    return [
        'id' => $skill['id'],
        'title' => $skill['title'],
        'description' => $skill['description'],
        'body' => $skill['body'],
        'prompt_name' => $skill['prompt_name'],
    ];
}
