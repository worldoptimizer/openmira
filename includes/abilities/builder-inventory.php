<?php

declare(strict_types=1);

/**
 * Abilities: Read-only Gutenberg and Bricks inventory.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/list-gutenberg-content', [
    'label' => __('List Gutenberg Content', domain: 'open-mira'),
    'description' => __(
        'Lists posts/pages/custom post types that contain Gutenberg block markup. Read-only. Use this to find candidate content before editing with parse_blocks/serialize_blocks.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to search, or "any".',
                'default' => 'any',
            ],
            'post_status' => [
                'type' => 'string',
                'description' => 'Post status to search, or "any".',
                'default' => 'any',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Optional title/content search term.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum posts to return.',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Result offset.',
                'default' => 0,
                'minimum' => 0,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'posts' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['posts', 'count'],
    ],
    'execute_callback' => 'openmira_list_gutenberg_content',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. Use openmira/read-gutenberg-content for the selected post before editing.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/read-gutenberg-content', [
    'label' => __('Read Gutenberg Content', domain: 'open-mira'),
    'description' => __(
        'Reads one post containing Gutenberg block markup and returns block summary plus optional raw post_content and parsed block data. Read-only.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID to read.',
                'minimum' => 1,
            ],
            'include_raw_content' => [
                'type' => 'boolean',
                'description' => 'Whether to include raw post_content.',
                'default' => false,
            ],
            'include_parsed_blocks' => [
                'type' => 'boolean',
                'description' => 'Whether to include parse_blocks output.',
                'default' => false,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'has_blocks' => ['type' => 'boolean'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'raw_content' => ['type' => 'string'],
            'parsed_blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['post', 'has_blocks', 'blocks'],
    ],
    'execute_callback' => 'openmira_read_gutenberg_content',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. For edits, preserve block comments by parsing with parse_blocks and serializing with serialize_blocks.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/list-bricks-content', [
    'label' => __('List Bricks Content', domain: 'open-mira'),
    'description' => __(
        'Lists posts and Bricks templates that contain Bricks Builder data in known Bricks meta areas. Read-only.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to search, or "any". Use "bricks_template" for templates.',
                'default' => 'any',
            ],
            'post_status' => [
                'type' => 'string',
                'description' => 'Post status to search, or "any".',
                'default' => 'any',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum posts/templates to return.',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Result offset.',
                'default' => 0,
                'minimum' => 0,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'bricks_available' => ['type' => 'boolean'],
            'meta_keys' => ['type' => 'object'],
            'posts' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['bricks_available', 'meta_keys', 'posts', 'count'],
    ],
    'execute_callback' => 'openmira_list_bricks_content',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. Use openmira/read-bricks-content on a selected post before planning any Bricks change.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/read-bricks-content', [
    'label' => __('Read Bricks Content', domain: 'open-mira'),
    'description' => __(
        'Reads Bricks Builder data for one post/template from known Bricks meta areas. Read-only. Returns element arrays and summary counts.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post or Bricks template ID to read.',
                'minimum' => 1,
            ],
            'area' => [
                'type' => 'string',
                'description' => 'Bricks area to read.',
                'enum' => ['all', 'header', 'content', 'footer'],
                'default' => 'all',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'meta_keys' => ['type' => 'object'],
            'areas' => ['type' => 'object'],
            'summary' => ['type' => 'object'],
        ],
        'required' => ['post', 'meta_keys', 'areas', 'summary'],
    ],
    'execute_callback' => 'openmira_read_bricks_content',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. Before any direct Bricks meta write, create a backup and preserve the element array shape.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List posts with Gutenberg block markup.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_list_gutenberg_content(array $input = []): array
{
    $query = new WP_Query([
        'post_type' => openmira_normalize_post_type_input((string) ($input['post_type'] ?? 'any')),
        'post_status' => openmira_normalize_post_status_input((string) ($input['post_status'] ?? 'any')),
        'posts_per_page' => openmira_normalize_limit($input['limit'] ?? 20),
        'offset' => max(0, (int) ($input['offset'] ?? 0)),
        's' => (string) ($input['search'] ?? ''),
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);

    $posts = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post || !has_blocks($post)) {
            continue;
        }
        $posts[] = openmira_build_post_inventory_item($post)
        + [
            'block_count' => count(parse_blocks($post->post_content)),
        ];
    }

    return [
        'posts' => $posts,
        'count' => count($posts),
    ];
}

/**
 * Read one Gutenberg post.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_read_gutenberg_content(array $input): array|WP_Error
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post((int) ($input['post_id'] ?? 0));
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', 'Post not found.');
    }

    $parsed_blocks = parse_blocks($post->post_content);
    $response = [
        'post' => openmira_build_post_inventory_item($post),
        'has_blocks' => has_blocks($post),
        'blocks' => openmira_summarize_blocks($parsed_blocks),
    ];

    if (openmira_truthy_input($input['include_raw_content'] ?? false)) {
        $response['raw_content'] = $post->post_content;
    }
    if (openmira_truthy_input($input['include_parsed_blocks'] ?? false)) {
        $response['parsed_blocks'] = $parsed_blocks;
    }

    return $response;
}

/**
 * List posts/templates with Bricks data.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_list_bricks_content(array $input = []): array
{
    $meta_keys = openmira_get_bricks_meta_keys();
    $query = new WP_Query([
        'post_type' => openmira_normalize_post_type_input((string) ($input['post_type'] ?? 'any')),
        'post_status' => openmira_normalize_post_status_input((string) ($input['post_status'] ?? 'any')),
        'posts_per_page' => openmira_normalize_limit($input['limit'] ?? 20),
        'offset' => max(0, (int) ($input['offset'] ?? 0)),
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
        'meta_query' => openmira_build_bricks_meta_query($meta_keys),
    ]);

    $posts = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }
        $posts[] = openmira_build_post_inventory_item($post)
        + [
            'areas' => openmira_get_bricks_area_counts($post->ID, $meta_keys),
        ];
    }

    return [
        'bricks_available' => openmira_is_bricks_available(),
        'meta_keys' => $meta_keys,
        'posts' => $posts,
        'count' => count($posts),
    ];
}

/**
 * Read Bricks data for one post/template.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_read_bricks_content(array $input): array|WP_Error
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post((int) ($input['post_id'] ?? 0));
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', 'Post not found.');
    }

    $requested_area = (string) ($input['area'] ?? 'all');
    $meta_keys = openmira_get_bricks_meta_keys();
    $areas = [];
    foreach ($meta_keys as $area => $meta_key) {
        if ($requested_area !== 'all' && $requested_area !== $area) {
            continue;
        }
        // @mago-expect analysis:mixed-assignment
        $value = get_post_meta($post->ID, $meta_key, single: true);
        $areas[$area] = [
            'meta_key' => $meta_key,
            'has_data' => is_array($value) && $value !== [],
            'element_count' => is_array($value) ? count($value) : 0,
            'elements' => is_array($value) ? $value : [],
        ];
    }

    return [
        'post' => openmira_build_post_inventory_item($post),
        'meta_keys' => $meta_keys,
        'areas' => $areas,
        'summary' => [
            'bricks_available' => openmira_is_bricks_available(),
            'total_elements' => array_sum(array_map(
                static fn(array $area): int => (int) $area['element_count'],
                $areas,
            )),
        ],
    ];
}

/**
 * Build a shared post inventory item.
 *
 * @return array<string, mixed>
 */
function openmira_build_post_inventory_item(WP_Post $post): array
{
    $edit_url = get_edit_post_link($post->ID, context: 'raw');

    return [
        'id' => $post->ID,
        'title' => get_the_title($post),
        'post_type' => $post->post_type,
        'status' => $post->post_status,
        'modified' => $post->post_modified_gmt,
        'edit_url' => is_string($edit_url) ? $edit_url : '',
    ];
}

/**
 * Summarize parsed Gutenberg blocks without duplicating all inner HTML by default.
 *
 * @param array<array-key, mixed> $blocks
 * @return list<array<string, mixed>>
 */
function openmira_summarize_blocks(array $blocks): array
{
    $summary = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $summary[] = [
            'blockName' => $block['blockName'] ?? null,
            'attrs' => $attrs,
            'innerBlockCount' => count($inner_blocks),
            'innerBlocks' => openmira_summarize_blocks($inner_blocks),
        ];
    }

    return $summary;
}

/**
 * Build a meta_query matching any known Bricks area.
 *
 * @param array<string, string> $meta_keys
 * @return array<int|string, mixed>
 */
function openmira_build_bricks_meta_query(array $meta_keys): array
{
    $query = ['relation' => 'OR'];
    foreach ($meta_keys as $meta_key) {
        $query[] = [
            'key' => $meta_key,
            'compare' => 'EXISTS',
        ];
    }

    return $query;
}

/**
 * Return per-area Bricks element counts for a post.
 *
 * @param array<string, string> $meta_keys
 * @return array<string, int>
 */
function openmira_get_bricks_area_counts(int $post_id, array $meta_keys): array
{
    $counts = [];
    foreach ($meta_keys as $area => $meta_key) {
        // @mago-expect analysis:mixed-assignment
        $value = get_post_meta($post_id, $meta_key, single: true);
        if (is_array($value) && $value !== []) {
            $counts[$area] = count($value);
        }
    }

    return $counts;
}

/**
 * Detect Bricks runtime availability.
 */
function openmira_is_bricks_available(): bool
{
    return class_exists('Bricks\\Elements') || defined('BRICKS_VERSION') || post_type_exists('bricks_template');
}

/**
 * Normalize limit input.
 */
function openmira_normalize_limit(mixed $value): int
{
    return min(100, max(1, (int) $value));
}

/**
 * Normalize post type input.
 *
 * @return string|list<string>
 */
function openmira_normalize_post_type_input(string $post_type): string|array
{
    $post_type = sanitize_key($post_type);
    if ($post_type === '' || $post_type === 'any') {
        return 'any';
    }

    return $post_type;
}

/**
 * Normalize post status input.
 *
 * @return string|list<string>
 */
function openmira_normalize_post_status_input(string $post_status): string|array
{
    $post_status = sanitize_key($post_status);
    if ($post_status === '' || $post_status === 'any') {
        return ['publish', 'draft', 'pending', 'private', 'future'];
    }

    return $post_status;
}

/**
 * Normalize REST/Abilities boolean input.
 */
function openmira_truthy_input(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

/**
 * Normalize false-like REST/Abilities boolean input.
 */
function openmira_falsey_input(mixed $value): bool
{
    return $value === false || $value === 0 || $value === '0' || $value === 'false';
}
