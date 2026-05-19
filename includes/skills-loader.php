<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Skills loader: discovers Markdown skill files and exposes them as MCP prompts.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_SKILLS_DIR = __DIR__ . '/skills';

const OPENMIRA_SKILL_ABILITY_NAMESPACE = 'openmira-skill';

const OPENMIRA_SKILL_PROMPT_PREFIX = 'openmira.';

/**
 * Return the parsed list of installed skills.
 *
 * @return array<string, array{id: string, title: string, description: string, body: string, path: string, prompt_name: string, ability_name: string}>
 */
function openmira_get_skills(): array
{
    $skills = [];
    $entries = is_dir(OPENMIRA_SKILLS_DIR) ? scandir(OPENMIRA_SKILLS_DIR) : [];
    foreach ((array) $entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        $dir = OPENMIRA_SKILLS_DIR . '/' . $entry;
        $file = $dir . '/SKILL.md';
        if (!is_dir($dir) || !is_file($file) || !is_readable($file)) {
            continue;
        }
        $id = sanitize_key($entry);
        if ($id === '' || $id !== $entry) {
            continue;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            continue;
        }
        $parsed = openmira_parse_skill_markdown($raw);
        $skills[$id] = [
            'id' => $id,
            'title' => $parsed['title'] !== '' ? $parsed['title'] : $id,
            'description' => $parsed['description'],
            'body' => $parsed['body'],
            'path' => $file,
            'prompt_name' => openmira_skill_prompt_name($id),
            'ability_name' => openmira_skill_ability_name($id),
        ];
    }

    ksort($skills);
    return $skills;
}

/**
 * Parse a SKILL.md file: YAML frontmatter (title, description) + markdown body.
 *
 * @return array{title: string, description: string, body: string}
 */
function openmira_parse_skill_markdown(string $raw): array
{
    $title = '';
    $description = '';
    $body = $raw;

    $match = [];
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $match)) {
        $front = $match[1];
        $body = $match[2];
        $front_lines = preg_split('/\r?\n/', $front);
        foreach ($front_lines === false ? [] : $front_lines as $line) {
            $kv = [];
            if (preg_match('/^\s*(title|description)\s*:\s*(.*)\s*$/i', $line, $kv)) {
                $key = strtolower($kv[1]);
                $value = trim($kv[2], characters: " \t\"'");
                if ($key === 'title') {
                    $title = $value;
                    continue;
                }
                if ($key === 'description') {
                    $description = $value;
                }
            }
        }
    }

    return ['title' => $title, 'description' => $description, 'body' => $body];
}

/**
 * Return the WordPress ability name backing a skill prompt.
 */
function openmira_skill_ability_name(string $skill_id): string
{
    return OPENMIRA_SKILL_ABILITY_NAMESPACE . '/' . sanitize_key($skill_id);
}

/**
 * Return the MCP prompt name for a skill.
 *
 * The bundled MCP Adapter follows the MCP name grammar and does not allow `/` in prompt names,
 * so Open Mira exposes skill prompts as `openmira.<skill-id>`.
 */
function openmira_skill_prompt_name(string $skill_id): string
{
    return OPENMIRA_SKILL_PROMPT_PREFIX . sanitize_key($skill_id);
}

/**
 * Resolve a skill from a skill ID or backing ability name.
 *
 * @return array{id: string, title: string, description: string, body: string, path: string, prompt_name: string, ability_name: string}|null
 */
function openmira_get_skill(string $skill_id): ?array
{
    $skill_id = sanitize_key($skill_id);
    $skills = openmira_get_skills();

    return $skills[$skill_id] ?? null;
}

/**
 * Register installed skill files as ability-backed MCP prompts.
 */
function openmira_register_skill_prompt_abilities(): void
{
    foreach (openmira_get_skills() as $skill) {
        $ability_name = $skill['ability_name'];
        if (wp_has_ability($ability_name)) {
            continue;
        }

        wp_register_ability($ability_name, [
            'label' => $skill['title'],
            'description' => $skill['description'],
            'category' => 'skills',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn(array $_input = []): array => [
                'description' => $skill['description'],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $skill['body'],
                        ],
                    ],
                ],
            ],
            'permission_callback' => static fn(): bool|WP_Error => openmira_permission_callback($ability_name),
            'meta' => [
                'show_in_rest' => true,
                'mcp' => [
                    'public' => true,
                    'type' => 'prompt',
                    '_openmira_skill_id' => $skill['id'],
                    'arguments' => [],
                ],
                'annotations' => [
                    'instructions' => 'Static Open Mira workflow skill loaded from SKILL.md.',
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }
}

add_filter(
    'mcp_adapter_prompt_name',
    static function (string $name, WP_Ability $ability): string {
        $meta = $ability->get_meta();
        $mcp_meta = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
        $skill_id_value = is_string($mcp_meta['_openmira_skill_id'] ?? null) ? $mcp_meta['_openmira_skill_id'] : '';
        if ($skill_id_value === '') {
            return $name;
        }

        return openmira_skill_prompt_name($skill_id_value);
    },
    priority: 10,
    accepted_args: 2,
);
