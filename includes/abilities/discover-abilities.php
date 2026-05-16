<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Discover Abilities (Open Mira replacement).
 *
 * Replaces the MCP Adapter's bundled discover-abilities tool so the response
 * includes Open Mira environment/usage instructions alongside the list of
 * abilities. These used to be sent via the MCP initialize handshake's
 * server_description, but some clients drop that; returning them here
 * guarantees the agent sees them on first tool discovery.
 */

if (!defined('ABSPATH')) {
    exit();
}

if (wp_has_ability('mcp-adapter/discover-abilities')) {
    wp_unregister_ability('mcp-adapter/discover-abilities');
}

if (wp_has_ability('mcp-adapter/discover-abilities')) {
    return;
}

wp_register_ability('mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', domain: 'open-mira'),
    'description' => __(
        'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information, plus Open Mira environment instructions.',
        domain: 'open-mira',
    ),
    'category' => 'mcp-adapter',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'detail' => [
                'type' => 'string',
                'description' => 'Discovery detail level. summary returns names only; compact includes parameter names and short usage hints; full includes input schemas.',
                'enum' => ['summary', 'compact', 'full'],
                'default' => 'compact',
            ],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'openmira_instructions' => [
                'type' => 'string',
                'description' => 'Open Mira environment and usage guidance for the agent.',
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                        'inputs' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'required' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'usage_hint' => ['type' => 'string'],
                        'input_schema' => ['type' => 'object'],
                    ],
                    'required' => ['name', 'label', 'description'],
                ],
            ],
        ],
        'required' => ['openmira_instructions', 'abilities'],
    ],
    'permission_callback' => static function (): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
        }
        /** @var string $cap */
        $cap = apply_filters('mcp_adapter_discover_abilities_capability', value: 'read');
        if (!current_user_can($cap)) {
            return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $cap));
        }
        return true;
    },
    'execute_callback' => static function (array $input = []): array {
        $detail = (string) ($input['detail'] ?? 'compact');
        if (!in_array(needle: $detail, haystack: ['summary', 'compact', 'full'], strict: true)) {
            $detail = 'compact';
        }

        $ability_list = [];
        foreach (wp_get_abilities() as $ability) {
            $meta = $ability->get_meta();
            if (!($meta['mcp']['public'] ?? false)) {
                continue;
            }
            if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
                continue;
            }
            $ability_list[] =
                [
                    'name' => $ability->get_name(),
                    'label' => $ability->get_label(),
                    'description' => $ability->get_description(),
                    'category' => $ability->get_category(),
                ] + openmira_discover_ability_detail($ability, $detail);
        }

        return [
            'openmira_instructions' => apply_filters(
                'openmira_discover_abilities_instructions',
                openmira_build_server_instructions(),
            ),
            'abilities' => $ability_list,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
    ],
]);

/**
 * Return compact discovery data for an ability.
 *
 * @return array<string, mixed>
 */
function openmira_discover_ability_detail(\WP_Ability $ability, string $detail): array
{
    if ($detail === 'summary') {
        return [];
    }

    $schema = $ability->get_input_schema();
    $required = [];
    if (is_array($schema['required'] ?? null)) {
        // @mago-expect analysis:mixed-assignment
        foreach ($schema['required'] as $property_name) {
            if (!is_string($property_name)) {
                continue;
            }

            $required[] = $property_name;
        }
    }

    if ($detail === 'full') {
        return [
            'required' => $required,
            'input_schema' => $schema,
            'usage_hint' => openmira_discover_usage_hint($ability),
        ];
    }

    return [
        'required' => $required,
        'inputs' => openmira_compact_input_schema($schema, $required),
        'usage_hint' => openmira_discover_usage_hint($ability),
    ];
}

/**
 * Compact a JSON schema into the fields agents need for first-pass calls.
 *
 * @param array<string, mixed> $schema
 * @param list<string> $required
 * @return list<array<string, mixed>>
 */
function openmira_compact_input_schema(array $schema, array $required): array
{
    // @mago-expect analysis:mixed-assignment
    $properties = $schema['properties'] ?? [];
    if (!is_array($properties)) {
        return [];
    }

    $inputs = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($properties as $name => $definition) {
        if (!is_string($name) || !is_array($definition)) {
            continue;
        }

        $item = [
            'name' => $name,
            'type' => is_string($definition['type'] ?? null) ? $definition['type'] : 'mixed',
            'required' => in_array(needle: $name, haystack: $required, strict: true),
        ];

        if (is_string($definition['description'] ?? null)) {
            $item['description'] = openmira_truncate_discovery_text($definition['description'], limit: 180);
        }
        if (is_array($definition['enum'] ?? null)) {
            $item['enum'] = array_values(array_filter(array: $definition['enum'], callback: 'is_scalar'));
        }
        if (array_key_exists('default', $definition)) {
            $item['default'] = $definition['default'];
        }

        $inputs[] = $item;
    }

    return $inputs;
}

/**
 * Return short usage hints for abilities where schema names are not enough.
 */
function openmira_discover_usage_hint(\WP_Ability $ability): string
{
    $name = $ability->get_name();
    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    $instructions = is_string($annotations['instructions'] ?? null) ? $annotations['instructions'] : '';

    $important = [
        'openmira/apply-patch',
        'openmira/search-code',
        'openmira/write-file',
        'openmira/scaffold-theme',
        'openmira/create-gutenberg-page',
        'openmira/screenshot-url',
        'openmira/probe-url',
        'openmira/graduate-sandbox-plugin',
        'openmira/find-hook-callers',
        'openmira/find-hook-registrants',
    ];

    if (!in_array(needle: $name, haystack: $important, strict: true) || $instructions === '') {
        return '';
    }

    return openmira_truncate_discovery_text($instructions, limit: 800);
}

/**
 * Truncate long discovery text without breaking compact responses.
 */
function openmira_truncate_discovery_text(string $text, int $limit): string
{
    $text = trim(preg_replace(pattern: '/\s+/', replacement: ' ', subject: $text) ?? $text);
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr(string: $text, offset: 0, length: $limit - 1)) . '…';
}
