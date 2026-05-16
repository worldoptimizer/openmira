<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Scaffold WordPress blocks.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/scaffold-block', [
    'label' => __('Scaffold Block', domain: 'open-mira'),
    'description' => __(
        'Creates a real PHP-rendered WordPress block with metadata, styles, editor script, and registration wiring.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Full block name, for example acme/property-card. If omitted, namespace and slug are used.',
                'default' => '',
            ],
            'namespace' => [
                'type' => 'string',
                'description' => 'Block namespace when name is omitted.',
                'default' => '',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'Block slug when name is omitted.',
                'default' => '',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Human-readable block title.',
                'minLength' => 1,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Block description.',
                'default' => 'AI-built dynamic block scaffolded by Open Mira.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Block category.',
                'default' => 'design',
            ],
            'icon' => [
                'type' => 'string',
                'description' => 'Dashicon slug.',
                'default' => 'layout',
            ],
            'target' => [
                'type' => 'string',
                'description' => 'Where to create the block.',
                'enum' => ['theme'],
                'default' => 'theme',
            ],
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug. Defaults to the active stylesheet.',
                'default' => '',
            ],
            'design_brief' => [
                'type' => 'string',
                'description' => 'Short visual direction for starter markup and CSS.',
                'default' => 'Polished reusable content card.',
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Overwrite existing block files. Existing files receive Open Mira backups.',
                'default' => false,
            ],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'block' => ['type' => 'object'],
            'files' => ['type' => 'array', 'items' => ['type' => 'object']],
            'registration' => ['type' => 'object'],
            'usage' => ['type' => 'string'],
            'audit' => ['type' => 'object'],
        ],
        'required' => ['block', 'files', 'registration', 'usage', 'audit'],
    ],
    'execute_callback' => 'openmira_scaffold_block',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for reusable design primitives. V1 creates PHP-rendered blocks in a theme without JS build tooling.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Scaffold a PHP-rendered block.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_scaffold_block(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/scaffold-block');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $block_name = openmira_scaffold_block_name($input);
    if (is_wp_error($block_name)) {
        return $block_name;
    }

    [$namespace, $slug] = explode(separator: '/', string: $block_name, limit: 2);
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('invalid_block_title', 'title is required.');
    }

    $theme_slug = sanitize_key((string) ($input['theme_slug'] ?? ''));
    if ($theme_slug === '') {
        $theme_slug = get_stylesheet();
    }

    $theme = wp_get_theme($theme_slug);
    if (!$theme->exists()) {
        return new WP_Error('theme_not_found', sprintf('Theme not found: %s', $theme_slug));
    }

    $theme_dir = trailingslashit(get_theme_root()) . $theme_slug;
    $block_dir = $theme_dir . '/blocks/' . $slug;
    if (!wp_mkdir_p($block_dir)) {
        return new WP_Error('block_directory_failed', sprintf('Could not create block directory: %s', $block_dir));
    }

    $description = trim((string) ($input['description'] ?? 'AI-built dynamic block scaffolded by Open Mira.'));
    $category = sanitize_key((string) ($input['category'] ?? 'design'));
    $icon = sanitize_key((string) ($input['icon'] ?? 'layout'));
    $design_brief = trim((string) ($input['design_brief'] ?? 'Polished reusable content card.'));
    $overwrite = ($input['overwrite'] ?? false) === true;
    $text_domain = sanitize_key(openmira_project_rule_string('text_domain', $theme_slug));
    if ($text_domain === '') {
        $text_domain = $theme_slug;
    }

    $files = openmira_scaffold_block_files(
        block_name: $block_name,
        title: $title,
        description: $description,
        category: $category !== '' ? $category : 'design',
        icon: $icon !== '' ? $icon : 'layout',
        design_brief: $design_brief,
        text_domain: $text_domain,
    );

    $written = [];
    foreach ($files as $relative_path => $contents) {
        $write_result = openmira_scaffold_block_write_file(
            path: $block_dir . '/' . $relative_path,
            contents: $contents,
            overwrite: $overwrite,
        );
        if (is_wp_error($write_result)) {
            return $write_result;
        }
        $written[] = $write_result;
    }

    $registration = openmira_scaffold_block_register_in_theme(
        theme_dir: $theme_dir,
        slug: $slug,
        block_name: $block_name,
        overwrite: $overwrite,
    );
    if (is_wp_error($registration)) {
        return $registration;
    }

    $audit = openmira_record_audit_event([
        'ability' => 'openmira/scaffold-block',
        'operation' => 'scaffold',
        'target_path' => openmira_display_path($block_dir),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => sprintf('%s, %d files', $block_name, count($written)),
    ]);

    return [
        'block' => [
            'name' => $block_name,
            'namespace' => $namespace,
            'slug' => $slug,
            'title' => $title,
            'target' => 'theme',
            'theme_slug' => $theme_slug,
            'path' => openmira_display_path($block_dir),
            'text_domain' => $text_domain,
        ],
        'files' => $written,
        'registration' => $registration,
        'usage' => sprintf(
            '<!-- wp:%s {"heading":"%s","text":"%s"} /-->',
            $block_name,
            esc_attr($title),
            esc_attr($description),
        ),
        'audit' => $audit,
    ];
}

/**
 * Resolve and validate a block name.
 *
 * @param array<string, mixed> $input
 * @return string|WP_Error
 */
function openmira_scaffold_block_name(array $input): string|WP_Error
{
    $name = strtolower(trim((string) ($input['name'] ?? '')));
    if ($name === '') {
        $namespace = sanitize_key((string) ($input['namespace'] ?? ''));
        $slug = sanitize_key((string) ($input['slug'] ?? ''));
        if ($namespace === '') {
            $namespace = sanitize_key(openmira_project_rule_string(key: 'function_prefix', default: 'openmira'));
        }
        if ($slug === '') {
            $slug = sanitize_title((string) ($input['title'] ?? ''));
        }
        $name = $namespace . '/' . $slug;
    }

    if (!preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name)) {
        return new WP_Error('invalid_block_name', 'Block name must look like namespace/block-slug.');
    }

    return $name;
}

/**
 * Return block files.
 *
 * @return array<string, string>
 */
// @mago-expect lint:excessive-parameter-list
function openmira_scaffold_block_files(
    string $block_name,
    string $title,
    string $description,
    string $category,
    string $icon,
    string $design_brief,
    string $text_domain,
): array {
    return [
        'block.json' => openmira_scaffold_block_json($block_name, $title, $description, $category, $icon, $text_domain),
        'render.php' => openmira_scaffold_block_render_php($title, $description, $text_domain),
        'style.css' => openmira_scaffold_block_style_css($design_brief),
        'editor.css' => openmira_scaffold_block_editor_css(),
        'index.js' => openmira_scaffold_block_index_js($block_name),
        'index.asset.php' => openmira_scaffold_block_asset_php(),
    ];
}

// @mago-expect lint:excessive-parameter-list
function openmira_scaffold_block_json(
    string $block_name,
    string $title,
    string $description,
    string $category,
    string $icon,
    string $text_domain,
): string {
    $json = wp_json_encode([
        '$schema' => 'https://schemas.wp.org/trunk/block.json',
        'apiVersion' => 3,
        'name' => $block_name,
        'version' => '0.1.0',
        'title' => $title,
        'category' => $category,
        'icon' => $icon,
        'description' => $description,
        'textdomain' => $text_domain,
        'attributes' => [
            'eyebrow' => ['type' => 'string', 'default' => 'Open Mira block'],
            'heading' => ['type' => 'string', 'default' => $title],
            'text' => ['type' => 'string', 'default' => $description],
            'buttonText' => ['type' => 'string', 'default' => 'Learn more'],
            'buttonUrl' => ['type' => 'string', 'default' => '#'],
        ],
        'supports' => [
            'anchor' => true,
            'align' => ['wide', 'full'],
            'color' => ['background' => true, 'text' => true],
            'spacing' => ['margin' => true, 'padding' => true],
            'typography' => ['fontSize' => true, 'lineHeight' => true],
            'html' => false,
        ],
        'style' => 'file:./style.css',
        'editorStyle' => 'file:./editor.css',
        'editorScript' => 'file:./index.js',
        'render' => 'file:./render.php',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json . "\n" : "{}\n";
}

function openmira_scaffold_block_render_php(string $title, string $description, string $text_domain): string
{
    $fallback_title = var_export($title, return: true);
    $fallback_description = var_export($description, return: true);
    $domain = var_export($text_domain, return: true);

    return <<<PHP
        <?php
        \$eyebrow = isset(\$attributes['eyebrow']) ? (string) \$attributes['eyebrow'] : __('Open Mira block', {$domain});
        \$heading = isset(\$attributes['heading']) ? (string) \$attributes['heading'] : __({$fallback_title}, {$domain});
        \$text = isset(\$attributes['text']) ? (string) \$attributes['text'] : __({$fallback_description}, {$domain});
        \$button_text = isset(\$attributes['buttonText']) ? (string) \$attributes['buttonText'] : __('Learn more', {$domain});
        \$button_url = isset(\$attributes['buttonUrl']) ? (string) \$attributes['buttonUrl'] : '#';
        ?>
        <section <?php echo get_block_wrapper_attributes(['class' => 'openmira-dynamic-block']); ?>>
            <div class="openmira-dynamic-block__inner">
                <?php if (\$eyebrow !== '') : ?>
                    <p class="openmira-dynamic-block__eyebrow"><?php echo esc_html(\$eyebrow); ?></p>
                <?php endif; ?>
                <h2 class="openmira-dynamic-block__heading"><?php echo esc_html(\$heading); ?></h2>
                <p class="openmira-dynamic-block__text"><?php echo esc_html(\$text); ?></p>
                <?php if (\$button_text !== '' && \$button_url !== '') : ?>
                    <a class="openmira-dynamic-block__button" href="<?php echo esc_url(\$button_url); ?>">
                        <?php echo esc_html(\$button_text); ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>
        PHP;
}

function openmira_scaffold_block_style_css(string $design_brief): string
{
    $brief = trim($design_brief) !== '' ? ' ' . trim($design_brief) : '';

    return <<<CSS
        /* Open Mira dynamic block.{$brief} */
        .wp-block .openmira-dynamic-block,
        .openmira-dynamic-block {
            border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
            border-radius: 28px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
            margin-block: clamp(2rem, 6vw, 5rem);
            overflow: hidden;
        }

        .openmira-dynamic-block__inner {
            background: linear-gradient(135deg, rgba(54, 87, 255, 0.10), rgba(255, 255, 255, 0.92));
            padding: clamp(2rem, 6vw, 5rem);
        }

        .openmira-dynamic-block__eyebrow {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            margin: 0 0 1rem;
            text-transform: uppercase;
        }

        .openmira-dynamic-block__heading {
            font-size: clamp(2rem, 5vw, 4.5rem);
            letter-spacing: -0.055em;
            line-height: 0.95;
            margin: 0;
            max-width: 11ch;
        }

        .openmira-dynamic-block__text {
            font-size: clamp(1rem, 2vw, 1.25rem);
            line-height: 1.65;
            margin: 1.5rem 0 0;
            max-width: 58ch;
        }

        .openmira-dynamic-block__button {
            background: currentColor;
            border-radius: 999px;
            color: #fff;
            display: inline-flex;
            font-weight: 750;
            margin-top: 2rem;
            padding: 0.9rem 1.25rem;
            text-decoration: none;
        }
        CSS;
}

function openmira_scaffold_block_editor_css(): string
{
    return <<<CSS
        .openmira-dynamic-block-editor {
            border: 1px dashed #8c8f94;
            border-radius: 16px;
            padding: 24px;
        }
        CSS;
}

function openmira_scaffold_block_index_js(string $block_name): string
{
    $name = wp_json_encode($block_name);

    return <<<JS
        (function (wp) {
            const el = wp.element.createElement;
            const Fragment = wp.element.Fragment;
            const InspectorControls = wp.blockEditor.InspectorControls;
            const RichText = wp.blockEditor.RichText;
            const useBlockProps = wp.blockEditor.useBlockProps;
            const PanelBody = wp.components.PanelBody;
            const TextControl = wp.components.TextControl;

            wp.blocks.registerBlockType({$name}, {
                edit: function (props) {
                    const attributes = props.attributes;
                    const blockProps = useBlockProps({ className: 'openmira-dynamic-block-editor' });

                    return el(Fragment, {},
                        el(InspectorControls, {},
                            el(PanelBody, { title: 'Button', initialOpen: true },
                                el(TextControl, {
                                    label: 'Button text',
                                    value: attributes.buttonText || '',
                                    onChange: function (value) { props.setAttributes({ buttonText: value }); }
                                }),
                                el(TextControl, {
                                    label: 'Button URL',
                                    value: attributes.buttonUrl || '',
                                    onChange: function (value) { props.setAttributes({ buttonUrl: value }); }
                                })
                            )
                        ),
                        el('section', blockProps,
                            el(RichText, {
                                tagName: 'p',
                                className: 'openmira-dynamic-block__eyebrow',
                                value: attributes.eyebrow || '',
                                placeholder: 'Eyebrow',
                                onChange: function (value) { props.setAttributes({ eyebrow: value }); }
                            }),
                            el(RichText, {
                                tagName: 'h2',
                                className: 'openmira-dynamic-block__heading',
                                value: attributes.heading || '',
                                placeholder: 'Heading',
                                onChange: function (value) { props.setAttributes({ heading: value }); }
                            }),
                            el(RichText, {
                                tagName: 'p',
                                className: 'openmira-dynamic-block__text',
                                value: attributes.text || '',
                                placeholder: 'Supporting text',
                                onChange: function (value) { props.setAttributes({ text: value }); }
                            })
                        )
                    );
                },
                save: function () {
                    return null;
                }
            });
        })(window.wp);
        JS;
}

function openmira_scaffold_block_asset_php(): string
{
    return <<<PHP
        <?php

        return [
            'dependencies' => ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            'version' => '0.1.0',
        ];
        PHP;
}

/**
 * Register a scaffolded block from a theme functions.php file.
 *
 * @return array<string, mixed>|WP_Error
 */
function openmira_scaffold_block_register_in_theme(
    string $theme_dir,
    string $slug,
    string $block_name,
    bool $overwrite,
): array|WP_Error {
    $functions_path = $theme_dir . '/functions.php';
    $old_content = is_file($functions_path) ? file_get_contents($functions_path) : '';
    if (!is_string($old_content)) {
        return new WP_Error('read_failed', sprintf('Could not read functions file: %s', $functions_path));
    }

    $function_prefix = sanitize_key(openmira_project_rule_string(key: 'function_prefix', default: 'openmira'));
    if ($function_prefix === '') {
        $function_prefix = 'openmira';
    }
    $function_name =
        $function_prefix . '_register_' . str_replace(search: '-', replace: '_', subject: $slug) . '_block';
    $marker_start = '// Open Mira block registration: ' . $block_name;
    $marker_end = '// End Open Mira block registration: ' . $block_name;
    $snippet = openmira_scaffold_block_registration_snippet($function_name, $slug, $marker_start, $marker_end);

    $new_content = $old_content;
    if (str_contains($old_content, $marker_start) && str_contains($old_content, $marker_end)) {
        if (!$overwrite) {
            return [
                'path' => openmira_display_path($functions_path),
                'changed' => false,
                'reason' => 'registration_exists',
                'content_hash' => is_file($functions_path) ? (string) hash_file('sha256', $functions_path) : '',
                'diff' => '',
            ];
        }
        $pattern =
            '/'
            . preg_quote($marker_start, delimiter: '/')
            . '.*?'
            . preg_quote($marker_end, delimiter: '/')
            . "\\n?/s";
        $new_content = preg_replace($pattern, $snippet, $old_content);
        if (!is_string($new_content)) {
            return new WP_Error('registration_replace_failed', 'Could not replace existing block registration.');
        }
    }
    if (!str_contains($old_content, $marker_start) || !str_contains($old_content, $marker_end)) {
        $new_content = rtrim($old_content) . "\n\n" . $snippet;
    }

    return openmira_scaffold_block_write_file($functions_path, $new_content, overwrite: true);
}

function openmira_scaffold_block_registration_snippet(
    string $function_name,
    string $slug,
    string $marker_start,
    string $marker_end,
): string {
    return <<<PHP
        {$marker_start}
        if (!function_exists('{$function_name}')) {
            function {$function_name}(): void
            {
                register_block_type(__DIR__ . '/blocks/{$slug}');
            }
        }
        add_action('init', '{$function_name}');
        {$marker_end}
        PHP;
}

/**
 * Write a scaffolded block file with backup and diff metadata.
 *
 * @return array<string, mixed>|WP_Error
 */
function openmira_scaffold_block_write_file(string $path, string $contents, bool $overwrite): array|WP_Error
{
    $exists = file_exists($path);
    if ($exists && !$overwrite) {
        return new WP_Error('file_exists', sprintf('File already exists: %s', openmira_display_path($path)));
    }

    $old_content = $exists ? file_get_contents($path) : '';
    if (!is_string($old_content)) {
        return new WP_Error('read_failed', sprintf('Could not read existing file: %s', openmira_display_path($path)));
    }

    $backup = $exists ? openmira_create_file_backup($path, operation: 'scaffold-block') : null;
    if (!wp_mkdir_p(dirname($path))) {
        return new WP_Error('directory_failed', sprintf(
            'Could not create directory: %s',
            openmira_display_path(dirname($path)),
        ));
    }
    if (file_put_contents($path, $contents) === false) {
        return new WP_Error('write_failed', sprintf('Could not write file: %s', openmira_display_path($path)));
    }
    $syntax_check = openmira_validate_php_write_or_rollback(
        resolved: $path,
        old_content: $exists ? $old_content : null,
        backup: $backup,
        ability: 'openmira/scaffold-block',
        operation: 'write_block_file',
        started_at: microtime(as_float: true),
    );
    if (is_wp_error($syntax_check)) {
        return $syntax_check;
    }

    return [
        'path' => openmira_display_path($path),
        'created' => !$exists,
        'size' => strlen($contents),
        'content_hash' => openmira_file_hash_content($contents),
        'diff' => openmira_build_unified_diff($old_content, $contents, $path),
        'backup' => $backup,
    ];
}
