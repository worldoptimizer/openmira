<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Skills loader: discovers built-in Markdown skill files plus registered skill sources
 * and exposes enabled skills as MCP prompts.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_SKILLS_DIR = __DIR__ . '/skills';

const OPENMIRA_USER_SKILLS_DIR = WP_CONTENT_DIR . '/openmira-skills';

const OPENMIRA_SKILL_ABILITY_NAMESPACE = 'openmira-skill';

const OPENMIRA_SKILL_PROMPT_PREFIX = 'openmira.';

const OPENMIRA_SKILL_BODY_MAX_BYTES = 65_536;

/**
 * Skill source contract.
 */
interface OpenMira_Skill_Source
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function list_skills(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function get_skill(string $id): ?array;
}

/**
 * Filesystem-backed built-in skills source.
 */
final class OpenMira_Filesystem_Skill_Source implements OpenMira_Skill_Source
{
    private string $base_dir;

    private string $source;

    public function __construct(string $base_dir, string $source)
    {
        $this->base_dir = $base_dir;
        $this->source = $source;
    }

    public function list_skills(): array
    {
        return openmira_scan_skills_directory($this->base_dir, $this->source);
    }

    public function get_skill(string $id): ?array
    {
        $valid = openmira_validate_skill_id($id);
        if (is_wp_error($valid)) {
            return null;
        }

        $file = rtrim($this->base_dir, characters: '/\\') . '/' . $id . '/SKILL.md';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }

        $parsed = openmira_parse_skill_markdown($raw);
        return openmira_normalize_skill([
            'id' => $id,
            'title' => $parsed['title'] !== '' ? $parsed['title'] : $id,
            'description' => $parsed['description'],
            'body' => $parsed['body'],
            'path' => $file,
            'source' => $this->source,
            'enabled' => $parsed['enable_prompt'] ?? true,
        ]);
    }
}

/**
 * Return the user-content skills directory.
 */
function openmira_user_skills_dir(): string
{
    $default = OPENMIRA_USER_SKILLS_DIR;
    $filtered = apply_filters('openmira_user_skills_dir', $default);

    return is_string($filtered) && $filtered !== '' ? $filtered : $default;
}

/**
 * Ensure the user-content skills directory exists and is writable.
 *
 * @deprecated 1.6.0 User-created skills are stored in the openmira_skill CPT. This helper remains for one
 *             minor version so legacy 1.5.x filesystem skills can be migrated.
 */
function openmira_ensure_user_skills_dir(): bool|WP_Error
{
    $dir = openmira_user_skills_dir();
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return new WP_Error('openmira_skills_dir_unavailable', 'Could not create the Open Mira user skills directory.');
    }
    if (!is_writable($dir)) {
        return new WP_Error('openmira_skills_dir_not_writable', 'The Open Mira user skills directory is not writable.');
    }

    return true;
}

/**
 * Validate a skill ID.
 */
function openmira_validate_skill_id(string $skill_id): bool|WP_Error
{
    if (preg_match('/^[a-z0-9][-a-z0-9._]{0,79}$/', $skill_id) !== 1) {
        return new WP_Error(
            'openmira_invalid_skill_id',
            'Skill ID must be 1-80 characters and contain only lowercase letters, numbers, dots, underscores, and hyphens.',
        );
    }

    return true;
}

/**
 * Return registered skill sources.
 *
 * @return array<string, OpenMira_Skill_Source>
 */
function openmira_get_skill_sources(): array
{
    $sources = [
        'filesystem' => new OpenMira_Filesystem_Skill_Source(OPENMIRA_SKILLS_DIR, 'filesystem'),
    ];

    /**
     * Filters Open Mira skill sources.
     *
     * Sources are consulted in array order. Later sources win ID conflicts.
     *
     * @param array<string, OpenMira_Skill_Source> $sources
     */
    $filtered = apply_filters('openmira_skill_sources', $sources);
    if (!is_array($filtered)) {
        return $sources;
    }

    $valid_sources = [];
    foreach ($filtered as $source_name => $source) {
        if (!is_string($source_name) || !$source instanceof OpenMira_Skill_Source) {
            continue;
        }
        $valid_sources[$source_name] = $source;
    }

    return $valid_sources;
}

/**
 * Return the parsed list of installed skills.
 *
 * @return array<string, array<string, mixed>>
 */
function openmira_get_skills(): array
{
    $skills = [];
    foreach (openmira_get_skill_sources() as $source_name => $source) {
        foreach ($source->list_skills() as $id => $skill) {
            $normalized = openmira_normalize_skill($skill);
            if (is_wp_error(openmira_validate_skill_id((string) $normalized['id']))) {
                continue;
            }
            $normalized_id = (string) $normalized['id'];
            $normalized['overrides_built_in'] = array_key_exists($normalized_id, $skills);
            if ($normalized['overrides_built_in']) {
                $normalized['source_label'] = openmira_skill_source_label_for_data(
                    (string) $normalized['source'],
                    overrides: true,
                );
            }
            $normalized['source_key'] = $source_name;
            $skills[$normalized_id] = $normalized;
        }
    }

    ksort($skills);
    return $skills;
}

/**
 * Normalize a skill array returned by a source.
 *
 * @param array<string, mixed> $skill
 * @return array<string, mixed>
 */
function openmira_normalize_skill(array $skill): array
{
    $id = is_string($skill['id'] ?? null) ? $skill['id'] : '';
    $title = is_string($skill['title'] ?? null) ? $skill['title'] : $id;
    $description = is_string($skill['description'] ?? null) ? $skill['description'] : '';
    $body = is_string($skill['body'] ?? null) ? $skill['body'] : '';
    $source = is_string($skill['source'] ?? null) ? $skill['source'] : 'unknown';
    $overrides = ($skill['overrides_built_in'] ?? false) === true;

    return array_merge($skill, [
        'id' => $id,
        'title' => $title !== '' ? $title : $id,
        'description' => $description,
        'body' => $body,
        'path' => is_string($skill['path'] ?? null) ? $skill['path'] : '',
        'prompt_name' => openmira_skill_prompt_name($id),
        'ability_name' => openmira_skill_ability_name($id),
        'source' => $source,
        'source_label' => openmira_skill_source_label_for_data($source, $overrides),
        'overrides_built_in' => $overrides,
        'enabled' => ($skill['enabled'] ?? true) !== false,
    ]);
}

/**
 * Return source label for data responses.
 */
function openmira_skill_source_label_for_data(string $source, bool $overrides): string
{
    if ($overrides) {
        return 'CPT (overrides built-in)';
    }
    if ($source === 'cpt') {
        return 'CPT';
    }
    if ($source === 'filesystem') {
        return 'Filesystem';
    }

    return $source;
}

/**
 * Scan one skills directory.
 *
 * @return array<string, array{id: string, title: string, description: string, body: string, path: string, source: string, enabled: bool}>
 */
function openmira_scan_skills_directory(string $base_dir, string $source): array
{
    $skills = [];
    $entries = is_dir($base_dir) ? scandir($base_dir) : [];
    foreach ((array) $entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        if (is_wp_error(openmira_validate_skill_id($entry))) {
            continue;
        }

        $dir = $base_dir . '/' . $entry;
        $file = $dir . '/SKILL.md';
        if (!is_dir($dir) || !is_file($file) || !is_readable($file)) {
            continue;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            continue;
        }
        $parsed = openmira_parse_skill_markdown($raw);
        $skills[$entry] = [
            'id' => $entry,
            'title' => $parsed['title'] !== '' ? $parsed['title'] : $entry,
            'description' => $parsed['description'],
            'body' => $parsed['body'],
            'path' => $file,
            'source' => $source,
            'enabled' => $parsed['enable_prompt'] ?? true,
        ];
    }

    return $skills;
}

/**
 * Parse a SKILL.md file: YAML frontmatter (title, description, enable_prompt) + markdown body.
 *
 * @return array{title: string, description: string, enable_prompt: bool|null, body: string}
 */
function openmira_parse_skill_markdown(string $raw): array
{
    $title = '';
    $description = '';
    $enable_prompt = null;
    $body = $raw;

    $match = [];
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $match)) {
        $front = $match[1];
        $body = $match[2];
        $front_lines = preg_split('/\r?\n/', $front);
        foreach ($front_lines === false ? [] : $front_lines as $line) {
            $kv = [];
            if (preg_match('/^\s*(title|description|enable_prompt)\s*:\s*(.*)\s*$/i', $line, $kv)) {
                $key = strtolower($kv[1]);
                $value = trim($kv[2], characters: " \t\"'");
                if ($key === 'title') {
                    $title = $value;
                    continue;
                }
                if ($key === 'description') {
                    $description = $value;
                    continue;
                }
                if ($key === 'enable_prompt') {
                    $enable_prompt = rest_sanitize_boolean($value);
                }
            }
        }
    }

    return ['title' => $title, 'description' => $description, 'enable_prompt' => $enable_prompt, 'body' => $body];
}

/**
 * Return a complete SKILL.md document.
 */
function openmira_build_skill_markdown(
    string $title,
    string $description,
    string $body,
    bool $enable_prompt = true,
): string {
    return (
        "---\n"
        . 'title: "'
        . openmira_skill_frontmatter_escape($title)
        . '"'
        . "\n"
        . 'description: "'
        . openmira_skill_frontmatter_escape($description)
        . '"'
        . "\n"
        . 'enable_prompt: '
        . ($enable_prompt ? 'true' : 'false')
        . "\n"
        . "---\n\n"
        . rtrim($body)
        . "\n"
    );
}

/**
 * Escape a scalar for the small frontmatter subset Open Mira writes.
 */
function openmira_skill_frontmatter_escape(string $value): string
{
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
}

/**
 * Return the WordPress ability name backing a skill prompt.
 */
function openmira_skill_ability_name(string $skill_id): string
{
    return OPENMIRA_SKILL_ABILITY_NAMESPACE . '/' . $skill_id;
}

/**
 * Return the MCP prompt name for a skill.
 *
 * The bundled MCP Adapter follows the MCP name grammar and does not allow `/` in prompt names,
 * so Open Mira exposes skill prompts as `openmira.<skill-id>`.
 */
function openmira_skill_prompt_name(string $skill_id): string
{
    return OPENMIRA_SKILL_PROMPT_PREFIX . $skill_id;
}

/**
 * Resolve a skill from a skill ID.
 *
 * @return array<string, mixed>|null
 */
function openmira_get_skill(string $skill_id): ?array
{
    if (is_wp_error(openmira_validate_skill_id($skill_id))) {
        return null;
    }

    foreach (array_reverse(openmira_get_skill_sources(), true) as $source) {
        $skill = $source->get_skill($skill_id);
        if (is_array($skill)) {
            $skills = openmira_get_skills();
            return $skills[$skill_id] ?? openmira_normalize_skill($skill);
        }
    }

    return null;
}

/**
 * Register installed skill files as ability-backed MCP prompts.
 */
function openmira_register_skill_prompt_abilities(): void
{
    foreach (openmira_get_skills() as $skill) {
        if (($skill['enabled'] ?? true) === false) {
            continue;
        }

        $ability_name = (string) $skill['ability_name'];
        if (wp_has_ability($ability_name)) {
            continue;
        }

        $skill_title = (string) $skill['title'];
        $skill_description = (string) $skill['description'];
        $skill_body = (string) $skill['body'];
        $skill_id = (string) $skill['id'];

        wp_register_ability($ability_name, [
            'label' => $skill_title,
            'description' => $skill_description,
            'category' => 'skills',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn(array $_input = []): array => [
                'description' => $skill_description,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $skill_body,
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
                    '_openmira_skill_id' => $skill_id,
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
