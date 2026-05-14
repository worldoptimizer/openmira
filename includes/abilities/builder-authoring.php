<?php

declare(strict_types=1);

/**
 * Abilities: Gutenberg content and design authoring helpers.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/list-gutenberg-patterns', [
    'label' => __('List Gutenberg Patterns', domain: 'open-mira'),
    'description' => __(
        'Lists Open Mira clean-room Gutenberg section patterns built from core blocks. Use these as safe content/design primitives before creating pages.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'patterns' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['patterns', 'count'],
    ],
    'execute_callback' => 'openmira_list_gutenberg_patterns',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. Patterns are core Gutenberg blocks and do not copy proprietary builder templates.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/render-gutenberg-pattern', [
    'label' => __('Render Gutenberg Pattern', domain: 'open-mira'),
    'description' => __(
        'Renders one Open Mira Gutenberg pattern to serialized core block markup with supplied copy, links, and design options. Does not write content.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'Pattern slug.',
                'enum' => ['hero', 'feature-grid', 'cta', 'faq', 'testimonial'],
            ],
            'content' => [
                'type' => 'object',
                'description' => 'Pattern-specific text, links, and item arrays.',
            ],
            'design' => [
                'type' => 'object',
                'description' => 'Optional design tokens: align, background_color, text_color, gradient.',
            ],
        ],
        'required' => ['pattern'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'pattern' => ['type' => 'string'],
            'markup' => ['type' => 'string'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['pattern', 'markup', 'blocks'],
    ],
    'execute_callback' => 'openmira_render_gutenberg_pattern',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only preview. Use markup with write-gutenberg-content, patch-gutenberg-blocks, or create-gutenberg-page.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/create-gutenberg-page', [
    'label' => __('Create Gutenberg Page', domain: 'open-mira'),
    'description' => __(
        'Creates a page or post from Open Mira Gutenberg patterns and optional raw block markup. Uses core blocks, validates block markup, and can create a backup when updating an existing post.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Optional existing post ID to replace.',
                'minimum' => 1,
            ],
            'post_type' => ['type' => 'string', 'description' => 'Post type for new content.', 'default' => 'page'],
            'title' => ['type' => 'string', 'description' => 'Post title.', 'minLength' => 1],
            'slug' => ['type' => 'string', 'description' => 'Optional post slug.'],
            'status' => [
                'type' => 'string',
                'description' => 'Post status.',
                'enum' => ['draft', 'publish', 'pending', 'private'],
                'default' => 'draft',
            ],
            'sections' => [
                'type' => 'array',
                'description' => 'Ordered sections. Each item may specify pattern/content/design or raw block_markup.',
                'items' => ['type' => 'object'],
                'minItems' => 1,
            ],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Required only when updating and optimistic locking is desired.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'create_backup' => [
                'type' => 'boolean',
                'description' => 'Whether to back up existing content when post_id is supplied.',
                'default' => true,
            ],
        ],
        'required' => ['title', 'sections'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'created' => ['type' => 'boolean'],
            'previous_hash' => ['type' => 'string'],
            'new_hash' => ['type' => 'string'],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'backup' => ['type' => 'object'],
        ],
        'required' => ['post', 'created', 'new_hash', 'block_count', 'blocks'],
    ],
    'execute_callback' => 'openmira_create_gutenberg_page',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use patterns for clean page drafts, then refine with patch-gutenberg-blocks. Updating existing content should pass expected_current_hash.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * List available clean-room Gutenberg patterns.
 *
 * @return array<string, mixed>
 */
function openmira_list_gutenberg_patterns(): array
{
    $patterns = [];
    foreach (openmira_get_gutenberg_pattern_definitions() as $slug => $definition) {
        $patterns[] = ['slug' => $slug] + $definition;
    }

    return [
        'patterns' => $patterns,
        'count' => count($patterns),
    ];
}

/**
 * Render a Gutenberg pattern without writing it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_render_gutenberg_pattern(array $input): array|WP_Error
{
    $pattern = sanitize_key((string) ($input['pattern'] ?? ''));
    $content = is_array($input['content'] ?? null) ? $input['content'] : [];
    $design = is_array($input['design'] ?? null) ? $input['design'] : [];
    $markup = openmira_render_gutenberg_pattern_markup($pattern, $content, $design);
    if (is_wp_error($markup)) {
        return $markup;
    }

    return [
        'pattern' => $pattern,
        'markup' => $markup,
        'blocks' => openmira_summarize_blocks(parse_blocks($markup)),
    ];
}

/**
 * Create or update Gutenberg content from sections.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_create_gutenberg_page(array $input): array|WP_Error
{
    $sections = openmira_build_gutenberg_page_sections($input['sections'] ?? []);
    if (is_wp_error($sections)) {
        return $sections;
    }

    $content = implode("\n\n", $sections);
    if (!has_blocks($content)) {
        return new WP_Error('content_has_no_blocks', 'Generated content does not contain Gutenberg blocks.');
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id > 0) {
        return openmira_update_gutenberg_page($post_id, $content, $input);
    }

    return openmira_insert_gutenberg_page($content, $input);
}

/**
 * Update an existing page/post with generated content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_update_gutenberg_page(int $post_id, string $content, array $input): array|WP_Error
{
    $write_result = openmira_write_gutenberg_content([
        'post_id' => $post_id,
        'content' => $content,
        'expected_current_hash' => (string) ($input['expected_current_hash'] ?? ''),
        'create_backup' => !openmira_falsey_input($input['create_backup'] ?? true),
        'backup_note' => 'Automatic backup before Open Mira page generation',
    ]);
    if (is_wp_error($write_result)) {
        return $write_result;
    }

    $updated = wp_update_post([
        'ID' => $post_id,
        'post_title' => sanitize_text_field((string) ($input['title'] ?? '')),
        'post_name' => sanitize_title((string) ($input['slug'] ?? '')),
        'post_status' => openmira_normalize_writable_post_status((string) ($input['status'] ?? 'draft')),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }

    $post = openmira_get_post_or_error($post_id);
    if (is_wp_error($post)) {
        return $post;
    }
    $write_result['post'] = openmira_build_post_inventory_item($post);
    $write_result['created'] = false;

    return $write_result;
}

/**
 * Insert a new page/post with generated content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_insert_gutenberg_page(string $content, array $input): array|WP_Error
{
    $post_type = openmira_normalize_writable_post_type((string) ($input['post_type'] ?? 'page'));
    if (is_wp_error($post_type)) {
        return $post_type;
    }

    $new_post_id = wp_insert_post([
        'post_type' => $post_type,
        'post_title' => sanitize_text_field((string) ($input['title'] ?? '')),
        'post_name' => sanitize_title((string) ($input['slug'] ?? '')),
        'post_status' => openmira_normalize_writable_post_status((string) ($input['status'] ?? 'draft')),
        'post_content' => wp_slash($content),
    ], wp_error: true);
    if (is_wp_error($new_post_id)) {
        return $new_post_id;
    }

    $post = openmira_get_post_or_error((int) $new_post_id);
    if (is_wp_error($post)) {
        return $post;
    }
    $parsed_blocks = parse_blocks($content);

    return [
        'post' => openmira_build_post_inventory_item($post),
        'created' => true,
        'new_hash' => openmira_hash_content($content),
        'block_count' => count($parsed_blocks),
        'blocks' => openmira_summarize_blocks($parsed_blocks),
    ];
}

/**
 * Return pattern metadata.
 *
 * @return array<string, array<string, mixed>>
 */
function openmira_get_gutenberg_pattern_definitions(): array
{
    return [
        'hero' => [
            'label' => 'Hero',
            'description' => 'Top page section with eyebrow, headline, body copy, and primary/secondary calls to action.',
            'content_keys' => [
                'eyebrow',
                'heading',
                'body',
                'primary_label',
                'primary_url',
                'secondary_label',
                'secondary_url',
            ],
            'design_keys' => ['align', 'background_color', 'text_color', 'gradient'],
        ],
        'feature-grid' => [
            'label' => 'Feature Grid',
            'description' => 'Three-column feature section with heading, intro, and repeatable feature cards.',
            'content_keys' => ['heading', 'body', 'items'],
            'design_keys' => ['align', 'background_color', 'text_color'],
        ],
        'cta' => [
            'label' => 'Call To Action',
            'description' => 'Focused conversion section with heading, short body copy, and one button.',
            'content_keys' => ['heading', 'body', 'button_label', 'button_url'],
            'design_keys' => ['align', 'background_color', 'text_color', 'gradient'],
        ],
        'faq' => [
            'label' => 'FAQ',
            'description' => 'Question and answer section using core Details blocks.',
            'content_keys' => ['heading', 'items'],
            'design_keys' => ['align', 'background_color', 'text_color'],
        ],
        'testimonial' => [
            'label' => 'Testimonial',
            'description' => 'Quote section with attribution and optional role/company line.',
            'content_keys' => ['quote', 'name', 'role'],
            'design_keys' => ['align', 'background_color', 'text_color'],
        ],
    ];
}

/**
 * Build section markup list from mixed pattern/raw sections.
 *
 * @return list<string>|WP_Error
 */
function openmira_build_gutenberg_page_sections(mixed $sections): array|WP_Error
{
    if (!is_array($sections) || $sections === []) {
        return new WP_Error('missing_sections', 'At least one section is required.');
    }

    $markup = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($sections as $section) {
        if (!is_array($section)) {
            return new WP_Error('invalid_section', 'Each section must be an object.');
        }
        if (is_string($section['block_markup'] ?? null)) {
            $raw_markup = trim($section['block_markup']);
            if (!has_blocks($raw_markup)) {
                return new WP_Error(
                    'invalid_section_markup',
                    'Raw section block_markup must contain Gutenberg block markup.',
                );
            }
            $markup[] = $raw_markup;
            continue;
        }
        $pattern = sanitize_key((string) ($section['pattern'] ?? ''));
        $content = is_array($section['content'] ?? null) ? $section['content'] : [];
        $design = is_array($section['design'] ?? null) ? $section['design'] : [];
        $rendered = openmira_render_gutenberg_pattern_markup($pattern, $content, $design);
        if (is_wp_error($rendered)) {
            return $rendered;
        }
        $markup[] = $rendered;
    }

    return $markup;
}

/**
 * Render a pattern slug to serialized core block markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_gutenberg_pattern_markup(string $pattern, array $content, array $design): string|WP_Error
{
    return match ($pattern) {
        'hero' => openmira_render_hero_pattern($content, $design),
        'feature-grid' => openmira_render_feature_grid_pattern($content, $design),
        'cta' => openmira_render_cta_pattern($content, $design),
        'faq' => openmira_render_faq_pattern($content, $design),
        'testimonial' => openmira_render_testimonial_pattern($content, $design),
        default => new WP_Error('unknown_pattern', 'Unknown Gutenberg pattern.'),
    };
}

/**
 * Render hero markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_hero_pattern(array $content, array $design): string
{
    $markup = openmira_paragraph(openmira_text($content['eyebrow'] ?? 'Open Mira'), ['fontSize' => 'small']);
    $markup .=
        "\n"
        . openmira_heading(
            openmira_text($content['heading'] ?? 'Build better WordPress content faster'),
            level: 1,
            attrs: [
                'fontSize' => 'xx-large',
            ],
        );
    $markup .=
        "\n"
        . openmira_paragraph(
            openmira_text(
                $content['body'] ?? 'A clean Gutenberg section generated from reusable Open Mira content primitives.',
            ),
            ['fontSize' => 'large'],
        );
    $markup .=
        "\n"
        . openmira_button(
            openmira_text($content['primary_label'] ?? 'Get started'),
            openmira_url($content['primary_url'] ?? '#'),
        );
    if (trim((string) ($content['secondary_label'] ?? '')) !== '') {
        $markup .=
            "\n"
            . openmira_button(
                openmira_text($content['secondary_label']),
                openmira_url($content['secondary_url'] ?? '#'),
                ['className' => 'is-style-outline'],
            );
    }

    return $markup;
}

/**
 * Render feature grid markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_feature_grid_pattern(array $content, array $design): string
{
    $markup = openmira_heading(openmira_text($content['heading'] ?? 'What you can build'), level: 2);
    $markup .=
        "\n"
        . openmira_paragraph(
            openmira_text(
                $content['body'] ?? 'Use reusable sections to draft consistent pages, then refine individual blocks.',
            ),
            ['fontSize' => 'medium'],
        );
    $items = openmira_normalize_pattern_items($content['items'] ?? [], [
        [
            'title' => 'Reusable sections',
            'body' => 'Compose pages from core Gutenberg blocks instead of opaque builder payloads.',
        ],
        ['title' => 'Safe edits', 'body' => 'Use backups and hash checks before replacing or patching content.'],
        ['title' => 'Portable markup', 'body' => 'Keep pages editable in the native WordPress block editor.'],
    ]);
    foreach (array_slice($items, offset: 0, length: 6) as $item) {
        $markup .= "\n" . openmira_heading(openmira_text($item['title'] ?? 'Feature'), level: 3);
        $markup .= "\n" . openmira_paragraph(openmira_text($item['body'] ?? 'Describe the value clearly.'));
    }

    return $markup;
}

/**
 * Render CTA markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_cta_pattern(array $content, array $design): string
{
    $markup = openmira_heading(openmira_text($content['heading'] ?? 'Ready to move faster?'), level: 2);
    $markup .=
        "\n"
        . openmira_paragraph(
            openmira_text(
                $content['body'] ?? 'Turn the next important idea into a draft page without leaving WordPress.',
            ),
            ['fontSize' => 'medium'],
        );
    $markup .=
        "\n"
        . openmira_button(
            openmira_text($content['button_label'] ?? 'Start now'),
            openmira_url($content['button_url'] ?? '#'),
        );

    return $markup;
}

/**
 * Render FAQ markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_faq_pattern(array $content, array $design): string
{
    $markup = openmira_heading(openmira_text($content['heading'] ?? 'Frequently asked questions'), level: 2);
    $items = openmira_normalize_pattern_items($content['items'] ?? [], [
        [
            'title' => 'Can this content be edited in Gutenberg?',
            'body' => 'Yes. These patterns use WordPress core blocks.',
        ],
        [
            'title' => 'Does this copy a proprietary template?',
            'body' => 'No. The markup is generated from clean-room Open Mira primitives.',
        ],
        [
            'title' => 'Can I reuse this with Bricks?',
            'body' => 'Use it as portable content, or map the section plan into Bricks elements after reading Bricks context.',
        ],
    ]);
    foreach (array_slice($items, offset: 0, length: 12) as $item) {
        $markup .= "\n" . openmira_heading(openmira_text($item['title'] ?? 'Question'), level: 3);
        $markup .= "\n" . openmira_paragraph(openmira_text($item['body'] ?? 'Answer.'));
    }

    return $markup;
}

/**
 * Render testimonial markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_testimonial_pattern(array $content, array $design): string
{
    $citation = openmira_text($content['name'] ?? 'Customer Name');
    $role = openmira_text($content['role'] ?? 'Role or company');
    if ($role !== '') {
        $citation .= ', ' . $role;
    }
    $quote =
        '<blockquote class="wp-block-quote"><p>'
        . esc_html(openmira_text(
            $content['quote'] ?? 'Open Mira helped us turn a rough idea into structured WordPress content.',
        ))
        . '</p><cite>'
        . esc_html($citation)
        . '</cite></blockquote>';

    return openmira_block('quote', [], $quote);
}

/**
 * Render a generic Gutenberg block comment wrapper.
 *
 * @param array<array-key, mixed> $attrs
 */
function openmira_block(string $name, array $attrs = [], string $inner = ''): string
{
    $encoded_attrs = wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = $attrs === [] || !is_string($encoded_attrs) ? '' : ' ' . $encoded_attrs;
    if ($inner === '') {
        return '<!-- wp:' . $name . $json . ' /-->';
    }

    return '<!-- wp:' . $name . $json . ' -->' . "\n" . $inner . "\n" . '<!-- /wp:' . $name . ' -->';
}

/**
 * Render heading markup.
 *
 * @param array<array-key, mixed> $attrs
 */
function openmira_heading(string $text, int $level = 2, array $attrs = []): string
{
    $level = min(6, max(1, $level));
    if ($level !== 2) {
        $attrs['level'] = $level;
    }
    $classes = ['wp-block-heading'];
    if (is_string($attrs['fontSize'] ?? null)) {
        $classes[] = 'has-' . sanitize_html_class($attrs['fontSize']) . '-font-size';
    }

    return openmira_block(
        'heading',
        $attrs,
        '<h' . $level . ' class="' . esc_attr(implode(' ', $classes)) . '">' . esc_html($text) . '</h' . $level . '>',
    );
}

/**
 * Render paragraph markup.
 *
 * @param array<array-key, mixed> $attrs
 */
function openmira_paragraph(string $text, array $attrs = []): string
{
    $classes = [];
    if (is_string($attrs['fontSize'] ?? null)) {
        $classes[] = 'has-' . sanitize_html_class($attrs['fontSize']) . '-font-size';
    }
    $class_attr = $classes === [] ? '' : ' class="' . esc_attr(implode(' ', $classes)) . '"';

    return openmira_block('paragraph', $attrs, '<p' . $class_attr . '>' . esc_html($text) . '</p>');
}

/**
 * Render a button block.
 *
 * @param array<array-key, mixed> $attrs
 */
function openmira_button(string $label, string $url, array $attrs = []): string
{
    return openmira_block('paragraph', [], '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>');
}

/**
 * Normalize repeatable pattern items.
 *
 * @param list<array<string, mixed>> $fallback
 * @return list<array<string, mixed>>
 */
function openmira_normalize_pattern_items(mixed $items, array $fallback): array
{
    if (!is_array($items) || $items === []) {
        return $fallback;
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized[] = [
            'title' => openmira_text($item['title'] ?? ''),
            'body' => openmira_text($item['body'] ?? ''),
        ];
    }

    return $normalized === [] ? $fallback : $normalized;
}

/**
 * Normalize plain text content.
 */
function openmira_text(mixed $value): string
{
    return trim(wp_strip_all_tags((string) $value));
}

/**
 * Normalize URL content.
 */
function openmira_url(mixed $value): string
{
    $url = trim((string) $value);
    if ($url === '' || $url === '#') {
        return '#';
    }

    return esc_url_raw($url);
}

/**
 * Normalize group alignment.
 */
function openmira_align(mixed $value): string
{
    $align = sanitize_key((string) $value);
    return in_array($align, ['full', 'wide'], strict: true) ? $align : 'wide';
}

/**
 * Normalize post status for write operations.
 */
function openmira_normalize_writable_post_status(string $status): string
{
    $status = sanitize_key($status);
    return in_array($status, ['draft', 'publish', 'pending', 'private'], strict: true) ? $status : 'draft';
}

/**
 * Normalize post type for creation.
 */
function openmira_normalize_writable_post_type(string $post_type): string|WP_Error
{
    $post_type = sanitize_key($post_type);
    if ($post_type === '') {
        $post_type = 'page';
    }
    if (!post_type_exists($post_type)) {
        return new WP_Error('unknown_post_type', 'Post type does not exist.');
    }
    if (!is_post_type_viewable($post_type) && $post_type !== 'page' && $post_type !== 'post') {
        return new WP_Error('unsupported_post_type', 'Post type is not viewable.');
    }

    return $post_type;
}
