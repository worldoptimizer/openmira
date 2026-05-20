<?php

declare(strict_types=1);

/**
 * Abilities: Persistent project memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/memory-store.php';

wp_register_ability('openmira/read-memory', [
    'label' => __('Read Memory', domain: 'open-mira'),
    'description' => __(
        'Reads persistent project memory stored in WordPress options. Use this at the start of a session to recover user preferences, architecture decisions, active builder choices, and implementation notes from prior sessions.',
        domain: 'open-mira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Optional memory key. Omit or pass an empty string to list all entries.',
                'pattern' => '^$|^[a-z0-9][\-a-z0-9._]{0,79}$',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'entries' => ['type' => 'object'],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['entries', 'count'],
    ],
    'execute_callback' => 'openmira_read_memory',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read this before substantial work. Then write durable decisions back with openmira/write-memory.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/memory-snapshot-resource', [
    'label' => __('Memory Snapshot', domain: 'open-mira'),
    'description' => __(
        'MCP resource containing the current Open Mira project memory snapshot as compact JSON.',
        domain: 'open-mira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_read_memory_resource',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => [
            'public' => false,
            'type' => 'resource',
            'uri' => 'openmira://memory/snapshot',
            'mimeType' => 'application/json',
        ],
        'annotations' => [
            'instructions' => 'Read-only memory snapshot resource. Use read-memory for targeted key reads or write-memory for durable updates.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

add_filter('mcp_adapter_default_server_config', callback: 'openmira_add_memory_resources_to_mcp_config', priority: 20);

wp_register_ability('openmira/write-memory', [
    'label' => __('Write Memory', domain: 'open-mira'),
    'description' => __(
        'Creates or updates persistent project memory for future AI sessions. Store durable facts only: builder choices, coding conventions, site architecture, client preferences, and decisions that should survive context loss.',
        domain: 'open-mira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Stable memory key.',
                'pattern' => '^[a-z0-9][\-a-z0-9._]{0,79}$',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Memory text. Keep it concise and factual.',
                'maxLength' => 20_000,
            ],
        ],
        'required' => ['key', 'value'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string'],
            'created' => ['type' => 'boolean'],
            'entry' => ['type' => 'object'],
        ],
        'required' => ['key', 'created', 'entry'],
    ],
    'execute_callback' => 'openmira_write_memory',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for durable project facts, not transient scratch notes. Prefer updating an existing key over creating duplicates.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Return the project memory snapshot as MCP resource content.
 *
 * @return list<array<string, string>>
 */
function openmira_read_memory_resource(): array
{
    $memory = openmira_read_memory();
    if (is_wp_error($memory)) {
        $memory = [
            'entries' => [],
            'count' => 0,
        ];
    }

    $json = wp_json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    return [[
        'uri' => 'openmira://memory/snapshot',
        'text' => $json,
        'mimeType' => 'application/json',
    ]];
}

/**
 * Add memory snapshot as a direct MCP resource.
 */
function openmira_add_memory_resources_to_mcp_config(mixed $config): mixed
{
    if (!is_array($config)) {
        return $config;
    }

    // @mago-expect analysis:mixed-assignment
    $resources = $config['resources'] ?? [];
    if (!is_array($resources)) {
        $resources = [];
    }

    $config['resources'] = array_merge($resources, openmira_get_memory_mcp_resources());
    return $config;
}

/**
 * Build direct MCP resources for memory context.
 *
 * @return list<object>
 */
function openmira_get_memory_mcp_resources(): array
{
    if (!class_exists(\WP\MCP\Domain\Resources\McpResource::class)) {
        return [];
    }

    $resource = \WP\MCP\Domain\Resources\McpResource::fromArray([
        'uri' => 'openmira://memory/snapshot',
        'name' => 'openmira-memory-snapshot',
        'title' => 'Memory Snapshot',
        'description' => 'Current Open Mira project memory snapshot as compact JSON.',
        'mimeType' => 'application/json',
        'handler' => static fn(): array => openmira_read_memory_resource(),
        'permission' => static fn(): bool => openmira_permission_bool('openmira/read-memory'),
    ]);

    if (is_wp_error($resource)) {
        return [];
    }

    return [$resource];
}

wp_register_ability('openmira/delete-memory', [
    'label' => __('Delete Memory', domain: 'open-mira'),
    'description' => __('Deletes one persistent project memory entry.', domain: 'open-mira'),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Memory key to delete.',
                'pattern' => '^[a-z0-9][\-a-z0-9._]{0,79}$',
            ],
        ],
        'required' => ['key'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['key', 'deleted'],
    ],
    'execute_callback' => 'openmira_delete_memory',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);
