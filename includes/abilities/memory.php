<?php

declare(strict_types=1);

/**
 * Abilities: Persistent project memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/memory-store.php';

wp_register_ability('novamira/read-memory', [
    'label' => __('Read Memory', domain: 'novamira'),
    'description' => __(
        'Reads persistent project memory stored in WordPress options. Use this at the start of a session to recover user preferences, architecture decisions, active builder choices, and implementation notes from prior sessions.',
        domain: 'novamira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Optional memory key. Omit or pass an empty string to list all entries.',
                'pattern' => '^$|^[a-z0-9][a-z0-9._-]{0,79}$',
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
    'execute_callback' => 'novamira_read_memory',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read this before substantial work. Then write durable decisions back with novamira/write-memory.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('novamira/write-memory', [
    'label' => __('Write Memory', domain: 'novamira'),
    'description' => __(
        'Creates or updates persistent project memory for future AI sessions. Store durable facts only: builder choices, coding conventions, site architecture, client preferences, and decisions that should survive context loss.',
        domain: 'novamira',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Stable memory key.',
                'pattern' => '^[a-z0-9][a-z0-9._-]{0,79}$',
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
    'execute_callback' => 'novamira_write_memory',
    'permission_callback' => 'novamira_permission_callback',
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

wp_register_ability('novamira/delete-memory', [
    'label' => __('Delete Memory', domain: 'novamira'),
    'description' => __('Deletes one persistent project memory entry.', domain: 'novamira'),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Memory key to delete.',
                'pattern' => '^[a-z0-9][a-z0-9._-]{0,79}$',
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
    'execute_callback' => 'novamira_delete_memory',
    'permission_callback' => 'novamira_permission_callback',
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
