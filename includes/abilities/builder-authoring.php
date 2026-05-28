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
                'enum' => ['hero', 'split', 'feature-grid', 'cta', 'faq', 'testimonial'],
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'Alias for pattern. Accepted for clients using list-gutenberg-patterns output.',
                'enum' => ['hero', 'split', 'feature-grid', 'cta', 'faq', 'testimonial'],
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
        'additionalProperties' => true,
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
            'instructions' => 'Read-only preview. Use markup with create-gutenberg-page, write-gutenberg-content for full rewrites, or read-blocks plus patch-blocks for dynamic block-level edits.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/create-gutenberg-page', [
    'label' => __('Create Gutenberg Page', domain: 'open-mira'),
    'description' => __(
        'Creates a page/post from sections. Use sections[*].block_markup for rendered raw Gutenberg markup, or pattern/content/design for Open Mira patterns.',
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
                'description' => 'Ordered sections. Each item may specify pattern/content/design, or raw markup via block_markup/raw_markup/markup.',
                'items' => ['type' => 'object'],
                'minItems' => 1,
            ],
            'raw_markup' => [
                'type' => 'string',
                'description' => 'Alias for a single raw Gutenberg block-markup section.',
            ],
            'block_markup' => [
                'type' => 'string',
                'description' => 'Alias for a single raw Gutenberg block-markup section.',
            ],
            'template' => [
                'type' => 'string',
                'description' => 'Optional page template slug to store in _wp_page_template, e.g. "blank" or "templates/full-width.php".',
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
        'required' => ['title'],
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
            'template' => ['type' => 'string'],
        ],
        'required' => ['post', 'created', 'new_hash', 'block_count', 'blocks'],
    ],
    'execute_callback' => 'openmira_create_gutenberg_page',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use patterns for clean page drafts. For existing content, prefer read-blocks plus patch-blocks for dynamic block-level edits; use expected_current_hash for full-content replacement.',
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
    $pattern = sanitize_key((string) ($input['pattern'] ?? $input['slug'] ?? ''));
    if ($pattern === '') {
        return new WP_Error(
            'missing_pattern',
            'Provide pattern or slug. Valid patterns: hero, split, feature-grid, cta, faq, testimonial.',
        );
    }
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
    $mode_error = openmira_require_act_mode('openmira/create-gutenberg-page');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $sections_input = openmira_normalize_gutenberg_page_sections_input($input);
    $sections = openmira_build_gutenberg_page_sections($sections_input);
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
    $template = openmira_apply_gutenberg_page_template($post_id, $input);
    if (is_wp_error($template)) {
        return $template;
    }
    if ($template !== '') {
        $write_result['template'] = $template;
    }

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
    $template = openmira_apply_gutenberg_page_template((int) $new_post_id, $input);
    if (is_wp_error($template)) {
        return $template;
    }
    $parsed_blocks = parse_blocks($content);

    $response = [
        'post' => openmira_build_post_inventory_item($post),
        'created' => true,
        'new_hash' => openmira_hash_content($content),
        'block_count' => count($parsed_blocks),
        'blocks' => openmira_summarize_blocks($parsed_blocks),
    ];
    if ($template !== '') {
        $response['template'] = $template;
    }

    return $response;
}

/**
 * Apply an optional page template to generated content.
 *
 * @param array<string, mixed> $input
 */
function openmira_apply_gutenberg_page_template(int $post_id, array $input): string|WP_Error
{
    if (!array_key_exists('template', $input)) {
        return '';
    }

    $template = sanitize_text_field((string) $input['template']);
    if ($template === '') {
        return '';
    }

    if ($template === 'default') {
        delete_post_meta($post_id, meta_key: '_wp_page_template');
        return 'default';
    }

    update_post_meta($post_id, meta_key: '_wp_page_template', meta_value: $template);

    return $template;
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
            'design_keys' => ['align', 'background_color', 'text_color', 'eyebrow_color', 'muted_color', 'gradient'],
        ],
        'feature-grid' => [
            'label' => 'Feature Grid',
            'description' => 'Three-column feature section with heading, intro, and repeatable feature cards.',
            'content_keys' => ['heading', 'body', 'items'],
            'design_keys' => [
                'align',
                'background_color',
                'text_color',
                'muted_color',
                'border_color',
                'accent_color',
                'number_color',
            ],
        ],
        'split' => [
            'label' => 'Split Brief',
            'description' => 'Two-column editorial section with left-side heading and right-side body/list content.',
            'content_keys' => ['eyebrow', 'left_heading', 'heading', 'right_body', 'body', 'items'],
            'design_keys' => ['align', 'background_color', 'text_color', 'muted_color', 'accent_color'],
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
        $raw_markup = openmira_gutenberg_section_raw_markup($section);
        if ($raw_markup !== '') {
            if (!has_blocks($raw_markup)) {
                return new WP_Error(
                    'invalid_section_markup',
                    'Raw section markup must contain Gutenberg block markup.',
                );
            }
            $markup[] = $raw_markup;
            continue;
        }
        $rendered = openmira_render_gutenberg_page_section_pattern($section);
        if (is_wp_error($rendered)) {
            return $rendered;
        }
        $markup[] = $rendered;
    }

    return $markup;
}

/**
 * Return raw block markup from a section using tolerant aliases.
 *
 * @param array<array-key, mixed> $section
 */
function openmira_gutenberg_section_raw_markup(array $section): string
{
    foreach (['block_markup', 'raw_markup', 'markup'] as $key) {
        if (is_string($section[$key] ?? null) && trim($section[$key]) !== '') {
            return trim($section[$key]);
        }
    }

    return '';
}

/**
 * Render a pattern-backed page section.
 *
 * @param array<array-key, mixed> $section
 */
function openmira_render_gutenberg_page_section_pattern(array $section): string|WP_Error
{
    $pattern = sanitize_key((string) ($section['pattern'] ?? $section['slug'] ?? ''));
    if ($pattern === '') {
        return new WP_Error(
            'missing_section_pattern',
            'Each section needs pattern/content/design or raw markup via block_markup, raw_markup, or markup.',
        );
    }

    $content = is_array($section['content'] ?? null) ? $section['content'] : [];
    $design = is_array($section['design'] ?? null) ? $section['design'] : [];

    return openmira_render_gutenberg_pattern_markup($pattern, $content, $design);
}

/**
 * Normalize page-level aliases to the section shape agents otherwise have to discover.
 *
 * @param array<string, mixed> $input
 * @return array<array-key, mixed>
 */
function openmira_normalize_gutenberg_page_sections_input(array $input): array
{
    if (is_array($input['sections'] ?? null) && $input['sections'] !== []) {
        return $input['sections'];
    }

    $raw_markup = '';
    if (is_string($input['raw_markup'] ?? null)) {
        $raw_markup = $input['raw_markup'];
    }
    if ($raw_markup === '' && is_string($input['block_markup'] ?? null)) {
        $raw_markup = $input['block_markup'];
    }
    if (trim($raw_markup) === '') {
        return [];
    }

    return [
        [
            'block_markup' => $raw_markup,
        ],
    ];
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
        'split' => openmira_render_split_pattern($content, $design),
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
    $text_color = openmira_design_color($design['text_color'] ?? '#fffaf4', fallback: '#fffaf4');
    $background_color = openmira_design_color($design['background_color'] ?? '#16191f', fallback: '#16191f');

    $markup = openmira_paragraph(openmira_text($content['eyebrow'] ?? 'Open Mira'), [
        'fontSize' => 'small',
        'style' => [
            'color' => ['text' => openmira_design_color($design['eyebrow_color'] ?? 'primary', fallback: '#6ee7b7')],
            'typography' => [
                'textTransform' => 'uppercase',
                'letterSpacing' => '0.16em',
                'fontStyle' => 'normal',
                'fontWeight' => '700',
            ],
        ],
    ]);
    $markup .=
        "\n"
        . openmira_heading(
            openmira_text($content['heading'] ?? 'Build better WordPress content faster'),
            level: 1,
            attrs: [
                'fontSize' => 'xx-large',
                'style' => ['color' => ['text' => $text_color]],
            ],
        );
    $markup .=
        "\n"
        . openmira_paragraph(
            openmira_text(
                $content['body'] ?? 'A clean Gutenberg section generated from reusable Open Mira content primitives.',
            ),
            [
                'fontSize' => 'large',
                'style' => [
                    'color' => [
                        'text' => openmira_design_color($design['muted_color'] ?? 'muted', fallback: '#cbd5e1'),
                    ],
                ],
            ],
        );

    $buttons = [
        [
            'label' => openmira_text($content['primary_label'] ?? 'Get started'),
            'url' => openmira_url($content['primary_url'] ?? '#'),
        ],
    ];
    if (trim((string) ($content['secondary_label'] ?? '')) !== '') {
        $buttons[] = [
            'label' => openmira_text($content['secondary_label']),
            'url' => openmira_url($content['secondary_url'] ?? '#'),
            'attrs' => ['className' => 'is-style-outline'],
        ];
    }
    $markup .= "\n" . openmira_buttons($buttons);

    return openmira_section_group($markup, $design, $background_color, $text_color);
}

/**
 * Render feature grid markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_render_feature_grid_pattern(array $content, array $design): string
{
    $text_color = openmira_design_color($design['text_color'] ?? '#111827', fallback: '#111827');
    $background_color = openmira_design_color($design['background_color'] ?? '#ffffff', fallback: '#ffffff');
    $muted_color = openmira_design_color($design['muted_color'] ?? 'muted', fallback: '#475569');
    $border_color = openmira_design_color($design['border_color'] ?? 'muted', fallback: '#e5e7eb');
    $number_color = openmira_design_color(
        $design['number_color'] ?? $design['accent_color'] ?? 'primary',
        fallback: '#3657ff',
    );

    $heading = array_key_exists(key: 'heading', array: $content)
        ? openmira_text($content['heading'])
        : 'What you can build';
    $body = array_key_exists(key: 'body', array: $content)
        ? openmira_text($content['body'])
        : 'Use reusable sections to draft consistent pages, then refine individual blocks.';
    $markup = '';
    if ($heading !== '') {
        $markup .= openmira_heading($heading, level: 2, attrs: [
            'textAlign' => 'center',
            'style' => ['color' => ['text' => $text_color]],
        ]);
    }
    if ($body !== '') {
        $markup .=
            ($markup !== '' ? "\n" : '')
            . openmira_paragraph($body, [
                'align' => 'center',
                'fontSize' => 'medium',
                'style' => ['color' => ['text' => $muted_color]],
            ]);
    }
    $items = openmira_normalize_pattern_items($content['items'] ?? [], [
        [
            'title' => 'Reusable sections',
            'body' => 'Compose pages from core Gutenberg blocks instead of opaque builder payloads.',
        ],
        ['title' => 'Safe edits', 'body' => 'Use backups and hash checks before replacing or patching content.'],
        ['title' => 'Portable markup', 'body' => 'Keep pages editable in the native WordPress block editor.'],
    ]);
    $columns = [];
    foreach (array_slice($items, offset: 0, length: 6) as $item) {
        $column = '';
        $accent = openmira_text($item['number'] ?? $item['label'] ?? '');
        if ($accent !== '') {
            $column .=
                openmira_paragraph($accent, [
                    'style' => [
                        'color' => [
                            'text' => openmira_design_color(
                                $item['number_color'] ?? $item['accent_color'] ?? $number_color,
                                fallback: $number_color,
                            ),
                        ],
                        'typography' => [
                            'fontWeight' => '800',
                            'letterSpacing' => '0.12em',
                            'textTransform' => 'uppercase',
                        ],
                    ],
                ]) . "\n";
        }

        $column .= openmira_heading(openmira_text($item['title'] ?? 'Feature'), level: 3, attrs: [
            'style' => ['color' => ['text' => $text_color]],
        ]) . "\n" . openmira_paragraph(openmira_text($item['body'] ?? 'Describe the value clearly.'), [
            'style' => ['color' => ['text' => $muted_color]],
        ]);
        $columns[] = $column;
    }
    $markup .= ($markup !== '' ? "\n" : '') . openmira_columns($columns, border_color: $border_color);

    return openmira_section_group($markup, $design, $background_color, $text_color);
}

/**
 * Render split brief markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_split_pattern(array $content, array $design): string
{
    $text_color = openmira_design_color($design['text_color'] ?? '#111827', fallback: '#111827');
    $background_color = openmira_design_color($design['background_color'] ?? '#ffffff', fallback: '#ffffff');
    $muted_color = openmira_design_color($design['muted_color'] ?? 'muted', fallback: '#475569');
    $accent_color = openmira_design_color($design['accent_color'] ?? 'primary', fallback: '#3657ff');
    $eyebrow = openmira_text($content['eyebrow'] ?? '');
    $left = '';
    if ($eyebrow !== '') {
        $left .=
            openmira_paragraph($eyebrow, [
                'fontSize' => 'small',
                'style' => [
                    'color' => ['text' => $accent_color],
                    'typography' => [
                        'fontWeight' => '800',
                        'letterSpacing' => '0.14em',
                        'textTransform' => 'uppercase',
                    ],
                ],
            ]) . "\n";
    }
    $left .= openmira_heading(
        openmira_text($content['left_heading'] ?? $content['heading'] ?? 'A clearer way to structure the brief'),
        level: 2,
        attrs: [
            'fontSize' => 'x-large',
            'style' => ['color' => ['text' => $text_color]],
        ],
    );

    $right_body = openmira_text(
        $content['right_body'] ?? $content['body']
            ?? 'Use a native two-column section when the design needs an editorial statement with supporting detail.',
    );
    $right = openmira_paragraph($right_body, [
        'fontSize' => 'medium',
        'style' => ['color' => ['text' => $muted_color]],
    ]);
    $items = openmira_normalize_pattern_items($content['items'] ?? [], []);
    if ($items !== []) {
        $right .= "\n" . openmira_split_pattern_list($items, $text_color, $muted_color);
    }

    $markup = openmira_split_columns([$left, $right]);

    return openmira_section_group($markup, $design, $background_color, $text_color);
}

/**
 * Render CTA markup.
 *
 * @param array<array-key, mixed> $content
 * @param array<array-key, mixed> $design
 */
function openmira_render_cta_pattern(array $content, array $design): string
{
    $text_color = openmira_design_color($design['text_color'] ?? '#fffaf4', fallback: '#fffaf4');
    $background_color = openmira_design_color($design['background_color'] ?? '#3657ff', fallback: '#3657ff');
    $markup = openmira_heading(openmira_text($content['heading'] ?? 'Ready to move faster?'), level: 2, attrs: [
        'textAlign' => 'center',
        'fontSize' => 'x-large',
        'style' => ['color' => ['text' => $text_color]],
    ]);
    $markup .=
        "\n"
        . openmira_paragraph(
            openmira_text(
                $content['body'] ?? 'Turn the next important idea into a draft page without leaving WordPress.',
            ),
            [
                'align' => 'center',
                'fontSize' => 'medium',
                'style' => ['color' => ['text' => $text_color]],
            ],
        );
    $markup .=
        "\n"
        . openmira_buttons([
            [
                'label' => openmira_text($content['button_label'] ?? 'Start now'),
                'url' => openmira_url($content['button_url'] ?? '#'),
                'attrs' => [
                    'style' => [
                        'color' => [
                            'background' => $text_color,
                            'text' => $background_color,
                        ],
                    ],
                ],
            ],
        ], justify: 'center');

    return openmira_section_group($markup, $design, $background_color, $text_color);
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
    $text_color = openmira_design_color($design['text_color'] ?? '#111827', fallback: '#111827');
    $background_color = openmira_design_color($design['background_color'] ?? '#ffffff', fallback: '#ffffff');
    $citation = openmira_text($content['name'] ?? 'Customer Name');
    $role = openmira_text($content['role'] ?? 'Role or company');
    if ($role !== '') {
        $citation .= ', ' . $role;
    }
    $attrs = [
        'style' => [
            'color' => [
                'text' => $text_color,
            ],
        ],
    ];
    $quote =
        '<blockquote class="wp-block-quote" style="'
        . esc_attr(openmira_style_string($attrs['style']))
        . '"><p>'
        . esc_html(openmira_text(
            $content['quote'] ?? 'Open Mira helped us turn a rough idea into structured WordPress content.',
        ))
        . '</p><cite>'
        . esc_html($citation)
        . '</cite></blockquote>';

    return openmira_section_group(openmira_block('quote', $attrs, $quote), $design, $background_color, $text_color);
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
 * Render a section group wrapper.
 *
 * @param array<array-key, mixed> $design
 */
function openmira_section_group(string $inner, array $design, string $background_color, string $text_color): string
{
    $align = openmira_align($design['align'] ?? 'full');
    $attrs = [
        'tagName' => 'section',
        'align' => $align,
        'style' => [
            'color' => [
                'background' => $background_color,
                'text' => $text_color,
            ],
            'spacing' => [
                'padding' => [
                    'top' => 'var:preset|spacing|70',
                    'bottom' => 'var:preset|spacing|70',
                ],
            ],
        ],
        'layout' => ['type' => 'constrained'],
    ];

    $classes = ['wp-block-group'];
    if ($align !== '') {
        $classes[] = 'align' . $align;
    }

    return openmira_block(
        'group',
        $attrs,
        '<section class="'
        . esc_attr(implode(' ', $classes))
        . '" style="'
        . esc_attr(openmira_style_string($attrs['style']))
        . '">'
        . "\n"
        . $inner
        . "\n"
        . '</section>',
    );
}

/**
 * Render a columns block from column inner markup.
 *
 * @param list<string> $columns
 */
function openmira_columns(array $columns, string $border_color = '#e5e7eb'): string
{
    $column_markup = [];
    foreach ($columns as $column) {
        $attrs = [
            'style' => [
                'border' => [
                    'radius' => '16px',
                    'color' => $border_color,
                    'width' => '1px',
                ],
                'spacing' => [
                    'padding' => [
                        'top' => 'var:preset|spacing|40',
                        'right' => 'var:preset|spacing|40',
                        'bottom' => 'var:preset|spacing|40',
                        'left' => 'var:preset|spacing|40',
                    ],
                ],
            ],
        ];
        $column_markup[] = openmira_block(
            'column',
            $attrs,
            '<div class="wp-block-column" style="'
            . esc_attr(openmira_style_string($attrs['style']))
            . '">'
            . "\n"
            . $column
            . "\n"
            . '</div>',
        );
    }

    $attrs = [
        'align' => 'wide',
        'style' => [
            'spacing' => [
                'padding' => ['top' => 'var:preset|spacing|50'],
                'blockGap' => [
                    'top' => 'var:preset|spacing|40',
                    'left' => 'var:preset|spacing|40',
                ],
            ],
        ],
    ];

    return openmira_block(
        'columns',
        $attrs,
        '<div class="wp-block-columns alignwide" style="'
        . esc_attr(openmira_style_string($attrs['style']))
        . '">'
        . "\n"
        . implode("\n", $column_markup)
        . "\n"
        . '</div>',
    );
}

/**
 * Render a split columns block without card borders.
 *
 * @param list<string> $columns
 */
function openmira_split_columns(array $columns): string
{
    $column_markup = [];
    foreach ($columns as $column) {
        $column_markup[] = openmira_block(
            'column',
            [],
            '<div class="wp-block-column">' . "\n" . $column . "\n" . '</div>',
        );
    }

    $attrs = [
        'align' => 'wide',
        'style' => [
            'spacing' => [
                'blockGap' => [
                    'top' => 'var:preset|spacing|50',
                    'left' => 'var:preset|spacing|60',
                ],
            ],
        ],
    ];

    return openmira_block(
        'columns',
        $attrs,
        '<div class="wp-block-columns alignwide" style="'
        . esc_attr(openmira_style_string($attrs['style']))
        . '">'
        . "\n"
        . implode("\n", $column_markup)
        . "\n"
        . '</div>',
    );
}

/**
 * Render split brief supporting list.
 *
 * @param list<array<string, mixed>> $items
 */
function openmira_split_pattern_list(array $items, string $text_color, string $muted_color): string
{
    $list_items = [];
    foreach (array_slice($items, offset: 0, length: 6) as $item) {
        $title = openmira_text($item['title'] ?? '');
        $body = openmira_text($item['body'] ?? '');
        if ($title === '' && $body === '') {
            continue;
        }
        $item_markup = $title === ''
            ? ''
            : '<strong style="'
            . esc_attr('color:' . openmira_css_value($text_color))
            . '">'
            . esc_html($title)
            . '</strong>';
        if ($body !== '') {
            $item_markup .=
                ($item_markup === '' ? '' : '<br>')
                . '<span style="'
                . esc_attr('color:' . openmira_css_value($muted_color))
                . '">'
                . esc_html($body)
                . '</span>';
        }
        $list_items[] = '<li>' . $item_markup . '</li>';
    }

    if ($list_items === []) {
        return '';
    }

    return openmira_block('list', [], '<ul class="wp-block-list">' . implode('', $list_items) . '</ul>');
}

/**
 * Render a buttons wrapper with one or more button blocks.
 *
 * @param list<array<string, mixed>> $buttons
 */
function openmira_buttons(array $buttons, string $justify = 'left'): string
{
    $inner = [];
    foreach ($buttons as $button) {
        $attrs = is_array($button['attrs'] ?? null) ? $button['attrs'] : [];
        $label = openmira_text($button['label'] ?? 'Button');
        $url = openmira_url($button['url'] ?? '#');
        $style = is_array($attrs['style'] ?? null) ? openmira_style_string($attrs['style']) : '';
        $style_attr = $style === '' ? '' : ' style="' . esc_attr($style) . '"';
        $inner[] = openmira_block(
            'button',
            $attrs,
            '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
            . esc_url($url)
            . '"'
            . $style_attr
            . '>'
            . esc_html($label)
            . '</a></div>',
        );
    }

    $attrs = ['layout' => ['type' => 'flex', 'justifyContent' => $justify]];

    return openmira_block('buttons', $attrs, '<div class="wp-block-buttons">' . implode("\n", $inner) . '</div>');
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
    if (is_string($attrs['textAlign'] ?? null)) {
        $classes[] = 'has-text-align-' . sanitize_html_class($attrs['textAlign']);
    }
    if (is_string($attrs['textColor'] ?? null)) {
        $classes[] = 'has-' . sanitize_html_class($attrs['textColor']) . '-color';
        $classes[] = 'has-text-color';
    }
    $style = is_array($attrs['style'] ?? null) ? openmira_style_string($attrs['style']) : '';
    $style_attr = $style === '' ? '' : ' style="' . esc_attr($style) . '"';

    return openmira_block(
        'heading',
        $attrs,
        '<h'
        . $level
        . ' class="'
        . esc_attr(implode(' ', $classes))
        . '"'
        . $style_attr
        . '>'
        . esc_html($text)
        . '</h'
        . $level
        . '>',
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
    if (is_string($attrs['align'] ?? null)) {
        $classes[] = 'has-text-align-' . sanitize_html_class($attrs['align']);
    }
    if (is_string($attrs['textColor'] ?? null)) {
        $classes[] = 'has-' . sanitize_html_class($attrs['textColor']) . '-color';
        $classes[] = 'has-text-color';
    }
    $class_attr = $classes === [] ? '' : ' class="' . esc_attr(implode(' ', $classes)) . '"';
    $style = is_array($attrs['style'] ?? null) ? openmira_style_string($attrs['style']) : '';
    $style_attr = $style === '' ? '' : ' style="' . esc_attr($style) . '"';

    return openmira_block('paragraph', $attrs, '<p' . $class_attr . $style_attr . '>' . esc_html($text) . '</p>');
}

/**
 * Render a button block.
 *
 * @param array<array-key, mixed> $attrs
 */
function openmira_button(string $label, string $url, array $attrs = []): string
{
    return openmira_buttons([
        [
            'label' => $label,
            'url' => $url,
            'attrs' => $attrs,
        ],
    ]);
}

/**
 * Convert supported block style arrays to inline CSS.
 *
 * @param array<array-key, mixed> $style
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_style_string(array $style): string
{
    $rules = [];
    $color = is_array($style['color'] ?? null) ? $style['color'] : [];
    $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : [];
    $padding = is_array($spacing['padding'] ?? null) ? $spacing['padding'] : [];
    $border = is_array($style['border'] ?? null) ? $style['border'] : [];
    $typography = is_array($style['typography'] ?? null) ? $style['typography'] : [];

    if (is_string($color['background'] ?? null)) {
        $rules[] = 'background-color:' . openmira_css_value($color['background']);
    }
    if (is_string($color['text'] ?? null)) {
        $rules[] = 'color:' . openmira_css_value($color['text']);
    }
    foreach (['top', 'right', 'bottom', 'left'] as $side) {
        if (is_string($padding[$side] ?? null)) {
            $rules[] = 'padding-' . $side . ':' . openmira_css_value($padding[$side]);
        }
    }
    if (is_string($border['radius'] ?? null)) {
        $rules[] = 'border-radius:' . openmira_css_value($border['radius']);
    }
    if (is_string($border['color'] ?? null)) {
        $rules[] = 'border-color:' . openmira_css_value($border['color']);
    }
    if (is_string($border['width'] ?? null)) {
        $rules[] = 'border-width:' . openmira_css_value($border['width']);
        $rules[] = 'border-style:solid';
    }
    if (is_string($typography['textTransform'] ?? null)) {
        $rules[] = 'text-transform:' . sanitize_key($typography['textTransform']);
    }
    if (is_string($typography['letterSpacing'] ?? null)) {
        $rules[] = 'letter-spacing:' . openmira_css_value($typography['letterSpacing']);
    }
    if (is_string($typography['fontStyle'] ?? null)) {
        $rules[] = 'font-style:' . sanitize_key($typography['fontStyle']);
    }
    if (is_string($typography['fontWeight'] ?? null)) {
        $rules[] = 'font-weight:' . openmira_css_value($typography['fontWeight']);
    }

    return implode(';', $rules);
}

/**
 * Normalize a user-supplied design color.
 */
function openmira_design_color(mixed $value, string $fallback): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return openmira_design_color_fallback($fallback);
    }

    $color = (string) sanitize_hex_color($raw);
    if ($color !== '') {
        return $color;
    }

    $preset_prefix = 'var:preset|color|';
    if (str_starts_with($raw, $preset_prefix)) {
        $slug = sanitize_key(substr($raw, strlen($preset_prefix)));
        return $slug === '' ? openmira_design_color_fallback($fallback) : 'var(--wp--preset--color--' . $slug . ')';
    }

    if (preg_match('/^[a-z][a-z0-9-]*$/i', $raw) === 1) {
        return 'var(--wp--preset--color--' . sanitize_key($raw) . ')';
    }

    return openmira_design_color_fallback($fallback);
}

/**
 * Normalize fallback colors without leaking null from sanitize_hex_color().
 */
function openmira_design_color_fallback(string $fallback): string
{
    $color = (string) sanitize_hex_color($fallback);
    return $color !== '' ? $color : '#000000';
}

/**
 * Normalize CSS values from known safe generated tokens.
 */
function openmira_css_value(string $value): string
{
    if (str_starts_with($value, 'var:preset|spacing|')) {
        return 'var(--wp--preset--spacing--' . sanitize_key(substr($value, strlen('var:preset|spacing|'))) . ')';
    }

    return preg_replace('/[^#%(),.\\-\\w\\s]/', replacement: '', subject: $value) ?? '';
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
        // @mago-expect analysis:mixed-assignment
        $title = $item['title'] ?? $item['heading'] ?? $item['label'] ?? '';
        // @mago-expect analysis:mixed-assignment
        $body = $item['body'] ?? $item['description'] ?? $item['text'] ?? '';
        $normalized_item = [
            'title' => openmira_text($title),
            'body' => openmira_text($body),
        ];
        if (array_key_exists('number', $item)) {
            $normalized_item['number'] = openmira_text($item['number']);
        }
        if (array_key_exists('label', $item)) {
            $normalized_item['label'] = openmira_text($item['label']);
        }
        if (array_key_exists('accent_color', $item)) {
            $normalized_item['accent_color'] = openmira_text($item['accent_color']);
        }
        if (array_key_exists('number_color', $item)) {
            $normalized_item['number_color'] = openmira_text($item['number_color']);
        }

        $normalized[] = $normalized_item;
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
