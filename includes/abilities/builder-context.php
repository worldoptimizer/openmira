<?php

declare(strict_types=1);

/**
 * Ability: Discover WordPress builder context.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/get-builder-context', [
    'label' => __('Get Builder Context', domain: 'novamira'),
    'description' => __(
        'Discovers the active WordPress builder stack and returns practical guidance for Gutenberg, Bricks Builder, and ACF/SCF-compatible custom fields. Use this before creating blocks, Bricks templates/elements, dynamic data integrations, or field-backed content models.',
        domain: 'novamira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'include_posts' => [
                'type' => 'boolean',
                'description' => 'Whether to include a small sample of Bricks templates and Gutenberg posts.',
                'default' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of posts/templates to include per integration.',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 50,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'instructions' => ['type' => 'string'],
            'gutenberg' => ['type' => 'object'],
            'bricks' => ['type' => 'object'],
            'custom_fields' => ['type' => 'object'],
            'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['instructions', 'gutenberg', 'bricks', 'custom_fields', 'sources'],
    ],
    'execute_callback' => 'novamira_get_builder_context',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this before changing Gutenberg, Bricks, ACF, or SCF content. It returns site-specific integration facts plus safe implementation rules.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Build the site-specific builder context.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function novamira_get_builder_context(array $input = []): array
{
    $include_posts = ($input['include_posts'] ?? true) !== false;
    $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
    $gutenberg_posts = $include_posts ? novamira_get_block_content_posts($limit) : [];
    $bricks_templates = $include_posts
        ? novamira_get_bricks_templates(
            novamira_get_bricks_template_post_type(),
            novamira_get_bricks_meta_keys(),
            $limit,
        )
        : [];

    return [
        'instructions' => novamira_get_builder_context_instructions(),
        'gutenberg' => novamira_get_gutenberg_context($gutenberg_posts),
        'bricks' => novamira_get_bricks_context($bricks_templates),
        'custom_fields' => novamira_get_custom_fields_context(),
        'sources' => [
            'https://developer.wordpress.org/block-editor/getting-started/fundamentals/block-json/',
            'https://developer.wordpress.org/reference/functions/register_block_type/',
            'https://academy.bricksbuilder.io/article/create-your-own-elements/',
            'https://academy.bricksbuilder.io/article/dynamic-data/',
            'https://developer.wordpress.org/secure-custom-fields/',
            'https://www.advancedcustomfields.com/resources/acf-security-principles/',
        ],
    ];
}

/**
 * Return implementation guidance for AI agents.
 */
function novamira_get_builder_context_instructions(): string
{
    return implode("\n", [
        'Builder integration rules:',
        '- Prefer public WordPress, Gutenberg, Bricks, ACF, and SCF APIs over direct database writes.',
        '- Do not copy proprietary plugin source, assets, branding, or private implementation text. Use public APIs, observed data shapes, and clean-room code.',
        '- For Gutenberg blocks, use block.json metadata and register_block_type/register_block_type_from_metadata. Prefer dynamic render templates when markup depends on PHP, post meta, or current query state.',
        '- For Gutenberg post_content edits, parse with parse_blocks and serialize with serialize_blocks. Do not regex-edit block comments unless the change is trivial and backed up.',
        '- For Bricks, detect the active plugin/theme before writing Bricks-specific code. Custom elements should extend \\Bricks\\Element and register with \\Bricks\\Elements::register_element on init after Bricks loads.',
        '- For Bricks content, prefer Bricks APIs and builder-safe hooks. If direct post meta edits are unavoidable, back up the original meta first and keep the Bricks element array valid.',
        '- For ACF/SCF, use the active plugin API when available: acf_add_local_field_group, get_field, update_field, get_fields. Keep field definitions in one source of truth.',
        '- When multiple modeling systems are active, ask the user which system owns new content types or fields before persisting structure.',
    ]);
}

/**
 * Discover Gutenberg/block-editor context.
 *
 * @param list<array<string, mixed>> $sample_posts
 * @return array<string, mixed>
 */
function novamira_get_gutenberg_context(array $sample_posts): array
{
    $registered_blocks = [];
    if (class_exists('WP_Block_Type_Registry')) {
        $block_types = WP_Block_Type_Registry::get_instance()->get_all_registered();
        foreach ($block_types as $name => $block_type) {
            $registered_blocks[] = [
                'name' => (string) $name,
                'title' => $block_type->title,
                'category' => $block_type->category,
            ];
        }
        usort($registered_blocks, static fn(array $first, array $second): int => strcmp(
            $first['name'],
            $second['name'],
        ));
    }

    return [
        'available' => function_exists('register_block_type'),
        'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
        'registered_block_count' => count($registered_blocks),
        'registered_blocks' => array_slice($registered_blocks, offset: 0, length: 100),
        'sample_block_posts' => $sample_posts,
        'safe_apis' => [
            'register_block_type',
            'register_block_type_from_metadata',
            'register_block_style',
            'register_block_pattern',
            'parse_blocks',
            'serialize_blocks',
            'do_blocks',
        ],
    ];
}

/**
 * Return a small sample of posts that contain block markup.
 *
 * @return list<array<string, mixed>>
 */
function novamira_get_block_content_posts(int $limit): array
{
    $query = new WP_Query([
        'post_type' => 'any',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        's' => '<!-- wp:',
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);

    $posts = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post || !has_blocks($post)) {
            continue;
        }
        $edit_url = get_edit_post_link($post->ID, context: 'raw');
        $posts[] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
            'edit_url' => is_string($edit_url) ? $edit_url : '',
        ];
    }

    return $posts;
}

/**
 * Discover Bricks Builder context.
 *
 * @param list<array<string, mixed>> $templates
 * @return array<string, mixed>
 */
function novamira_get_bricks_context(array $templates): array
{
    $active = class_exists('Bricks\\Elements') || defined('BRICKS_VERSION') || post_type_exists('bricks_template');
    $version = defined('BRICKS_VERSION') ? (string) constant('BRICKS_VERSION') : '';
    $template_post_type = novamira_get_bricks_template_post_type();
    $meta_keys = novamira_get_bricks_meta_keys();

    return [
        'available' => $active,
        'version' => $version,
        'template_post_type' => $template_post_type,
        'meta_keys' => $meta_keys,
        'global_option_keys' => [
            defined('BRICKS_DB_GLOBAL_SETTINGS')
                ? (string) constant('BRICKS_DB_GLOBAL_SETTINGS')
                : 'bricks_global_settings',
            defined('BRICKS_DB_GLOBAL_ELEMENTS')
                ? (string) constant('BRICKS_DB_GLOBAL_ELEMENTS')
                : 'bricks_global_elements',
            defined('BRICKS_DB_GLOBAL_CLASSES')
                ? (string) constant('BRICKS_DB_GLOBAL_CLASSES')
                : 'bricks_global_classes',
            defined('BRICKS_DB_GLOBAL_VARIABLES')
                ? (string) constant('BRICKS_DB_GLOBAL_VARIABLES')
                : 'bricks_global_variables',
        ],
        'safe_hooks' => [
            'bricks/builder/i18n',
            'bricks/builder/elements',
            'bricks/elements/{element_name}/controls',
            'bricks/elements/{element_name}/control_groups',
            'bricks/frontend/render_element',
            'bricks/element/settings',
            'bricks/dynamic_tags_list',
            'bricks/dynamic_data/render_tag',
            'bricks/form/validate',
            'bricks/form/action/{form_action}',
        ],
        'safe_apis' => [
            '\\Bricks\\Elements::register_element',
            '\\Bricks\\Element',
            'bricks_is_builder',
            'bricks_is_builder_main',
            'bricks_is_builder_iframe',
            'bricks_is_builder_call',
            'bricks_render_dynamic_data',
        ],
        'templates' => $templates,
    ];
}

/**
 * Return the Bricks template post type.
 */
function novamira_get_bricks_template_post_type(): string
{
    return defined('BRICKS_DB_TEMPLATE_SLUG') ? (string) constant('BRICKS_DB_TEMPLATE_SLUG') : 'bricks_template';
}

/**
 * Return Bricks content meta keys, preferring active constants when available.
 *
 * @return array<string, string>
 */
function novamira_get_bricks_meta_keys(): array
{
    return [
        'header' => defined('BRICKS_DB_PAGE_HEADER')
            ? (string) constant('BRICKS_DB_PAGE_HEADER')
            : '_bricks_page_header_2',
        'content' => defined('BRICKS_DB_PAGE_CONTENT')
            ? (string) constant('BRICKS_DB_PAGE_CONTENT')
            : '_bricks_page_content_2',
        'footer' => defined('BRICKS_DB_PAGE_FOOTER')
            ? (string) constant('BRICKS_DB_PAGE_FOOTER')
            : '_bricks_page_footer_2',
    ];
}

/**
 * Return a small sample of Bricks templates.
 *
 * @param array<string, string> $meta_keys
 * @return list<array<string, mixed>>
 */
function novamira_get_bricks_templates(string $template_post_type, array $meta_keys, int $limit): array
{
    if (!post_type_exists($template_post_type)) {
        return [];
    }

    $query = new WP_Query([
        'post_type' => $template_post_type,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);

    $templates = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }
        $areas = [];
        foreach ($meta_keys as $area => $meta_key) {
            // @mago-expect analysis:mixed-assignment
            $value = get_post_meta($post->ID, $meta_key, single: true);
            if (is_array($value) && $value !== []) {
                $areas[$area] = count($value);
            }
        }
        $edit_url = get_edit_post_link($post->ID, context: 'raw');
        $templates[] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
            'areas' => $areas,
            'edit_url' => is_string($edit_url) ? $edit_url : '',
        ];
    }

    return $templates;
}

/**
 * Discover ACF/SCF-compatible custom fields context.
 *
 * @return array<string, mixed>
 */
function novamira_get_custom_fields_context(): array
{
    $providers = [];
    if (function_exists('acf')) {
        $providers[] = [
            'name' => 'ACF/SCF API',
            'available' => true,
            'version' => function_exists('acf_get_setting') ? (string) acf_get_setting('version') : '',
        ];
    }
    if (class_exists('ACF')) {
        $providers[] = ['name' => 'Advanced Custom Fields class', 'available' => true];
    }
    if (defined('SCF_VERSION')) {
        $providers[] = [
            'name' => 'Secure Custom Fields',
            'available' => true,
            'version' => (string) constant('SCF_VERSION'),
        ];
    }

    return [
        'available' => $providers !== [],
        'providers' => $providers,
        'safe_apis' => [
            'acf_add_local_field_group',
            'acf_get_field_groups',
            'acf_get_fields',
            'get_field',
            'get_fields',
            'update_field',
        ],
        'storage_note' => 'ACF/SCF values use WordPress meta/options storage. Field definitions should stay in the active field plugin API or JSON/PHP local definitions, not split across ad-hoc register_meta code.',
    ];
}
