<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Scaffold WordPress themes.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/scaffold-theme', [
    'label' => __('Scaffold Theme', domain: 'open-mira'),
    'description' => __(
        'Creates a real WordPress theme: block, classic, or child. Pass activate=true to make it live without WP-CLI.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'type' => [
                'type' => 'string',
                'description' => 'Theme type to create.',
                'enum' => ['block', 'classic', 'child'],
                'default' => 'block',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'Theme directory slug.',
                'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Human-readable theme name.',
                'minLength' => 1,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Theme description.',
                'default' => 'AI-built WordPress theme scaffolded by Open Mira.',
            ],
            'parent_slug' => [
                'type' => 'string',
                'description' => 'Parent theme slug for child themes.',
                'default' => '',
            ],
            'design_brief' => [
                'type' => 'string',
                'description' => 'Short visual direction used in starter content and CSS.',
                'default' => 'Editorial, polished, responsive site design.',
            ],
            'activate' => [
                'type' => 'boolean',
                'description' => 'Activate the theme after scaffolding.',
                'default' => false,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Overwrite existing files. Existing files receive Open Mira backups.',
                'default' => false,
            ],
            'force_clean' => [
                'type' => 'boolean',
                'description' => 'Move an existing theme directory to an Open Mira backup location before scaffolding fresh files.',
                'default' => false,
            ],
            'include_blank_template' => [
                'type' => 'boolean',
                'description' => 'For block themes, also create templates/blank.html for full-bleed landing pages.',
                'default' => false,
            ],
        ],
        'required' => ['slug', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'theme' => ['type' => 'object'],
            'files' => ['type' => 'array', 'items' => ['type' => 'object']],
            'next_write_hints' => ['type' => 'object'],
            'activated' => ['type' => 'boolean'],
            'preview_url' => ['type' => 'string'],
            'audit' => ['type' => 'object'],
            'cleanup_backup' => ['type' => 'object'],
        ],
        'required' => ['theme', 'files', 'activated', 'preview_url', 'audit'],
    ],
    'execute_callback' => 'openmira_scaffold_theme',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for real theme work. Prefer block themes for design-heavy builds unless the user asks for classic PHP templates.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Scaffold a WordPress theme.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_scaffold_theme(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/scaffold-theme');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $preferred_type = openmira_project_rule_string(key: 'preferred_theme_type', default: 'block');
    $default_type = in_array($preferred_type, ['block', 'classic', 'child'], strict: true) ? $preferred_type : 'block';
    $requested_type = (string) ($input['type'] ?? $default_type);
    $type = in_array($requested_type, ['block', 'classic', 'child'], strict: true) ? $requested_type : $default_type;
    $slug = sanitize_key((string) ($input['slug'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? 'AI-built WordPress theme scaffolded by Open Mira.'));
    $design_brief = trim((string) ($input['design_brief'] ?? 'Editorial, polished, responsive site design.'));
    $parent_slug = sanitize_key((string) ($input['parent_slug'] ?? ''));
    $text_domain = sanitize_key(openmira_project_rule_string('text_domain', $slug));
    if ($text_domain === '') {
        $text_domain = $slug;
    }
    $activate = ($input['activate'] ?? false) === true;
    $overwrite = ($input['overwrite'] ?? false) === true;
    $force_clean = ($input['force_clean'] ?? false) === true;
    $include_blank_template = ($input['include_blank_template'] ?? false) === true;

    if ($slug === '' || $name === '') {
        return new WP_Error('invalid_theme_identity', 'slug and name are required.');
    }
    if ($type === 'child' && $parent_slug === '') {
        return new WP_Error('missing_parent_slug', 'parent_slug is required for child themes.');
    }

    $theme_dir = trailingslashit(get_theme_root()) . $slug;
    $cleanup_backup = null;
    if ($force_clean && is_dir($theme_dir)) {
        $cleanup_backup = openmira_scaffold_theme_move_existing_directory($theme_dir, $slug);
        if (is_wp_error($cleanup_backup)) {
            return $cleanup_backup;
        }
    }

    $directories_created = openmira_ensure_parent_dir($theme_dir);
    if (is_wp_error($directories_created)) {
        return new WP_Error(
            'theme_directory_failed',
            sprintf('Could not create theme directory: %s', $theme_dir),
            $directories_created->get_error_data(),
        );
    }

    $files = openmira_scaffold_theme_files(
        type: $type,
        slug: $slug,
        name: $name,
        description: $description,
        design_brief: $design_brief,
        text_domain: $text_domain,
        parent_slug: $parent_slug,
        include_blank_template: $include_blank_template,
    );

    $written = [];
    foreach ($files as $relative_path => $contents) {
        $write_result = openmira_scaffold_theme_write_file(
            path: $theme_dir . '/' . $relative_path,
            contents: $contents,
            overwrite: $overwrite,
        );
        if (is_wp_error($write_result)) {
            return $write_result;
        }
        $written[] = $write_result;
    }

    wp_clean_themes_cache(clear_update_cache: true);
    if (function_exists('search_theme_directories')) {
        search_theme_directories(force: true);
    }
    clearstatcache(clear_realpath_cache: true, filename: $theme_dir);
    $registry_theme = wp_get_theme($slug);
    $registry_exists = $registry_theme->exists();

    $activated = false;
    if ($activate) {
        switch_theme($slug);
        $activated = get_stylesheet() === $slug;
        wp_clean_themes_cache(clear_update_cache: true);
    }

    $audit = openmira_record_audit_event([
        'ability' => 'openmira/scaffold-theme',
        'operation' => $activated ? 'scaffold_and_activate' : 'scaffold',
        'target_path' => openmira_display_path($theme_dir),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => sprintf(
            '%d files%s',
            count($written),
            is_array($cleanup_backup) ? '; existing directory moved to backup' : '',
        ),
        'diff' => openmira_join_file_diffs_for_audit($written),
    ]);

    $result = [
        'theme' => [
            'type' => $type,
            'slug' => $slug,
            'name' => $name,
            'text_domain' => $text_domain,
            'path' => openmira_display_path($theme_dir),
            'registry_exists' => $registry_exists,
        ],
        'files' => $written,
        'next_write_hints' => openmira_scaffold_theme_next_write_hints($written, $slug),
        'activated' => $activated,
        'preview_url' => home_url('/'),
        'audit' => $audit,
    ];
    if (is_array($cleanup_backup)) {
        $result['cleanup_backup'] = $cleanup_backup;
    }

    return $result;
}

/**
 * Return high-value hashes agents should pass to immediate follow-up writes.
 *
 * @param list<array<string, mixed>> $written
 * @return array<string, mixed>
 */
function openmira_scaffold_theme_next_write_hints(array $written, string $slug): array
{
    $hints = [
        'use_expected_current_hash' => true,
    ];

    $theme_json = openmira_scaffold_theme_find_written_file($written, "wp-content/themes/{$slug}/theme.json");
    if ($theme_json !== null) {
        $hints['theme_json'] = $theme_json;
    }

    $theme_css = openmira_scaffold_theme_find_written_file($written, "wp-content/themes/{$slug}/assets/css/theme.css");
    if ($theme_css !== null) {
        $hints['theme_css'] = $theme_css;
    }

    $style_css = openmira_scaffold_theme_find_written_file($written, "wp-content/themes/{$slug}/style.css");
    if ($style_css !== null) {
        $hints['style_css'] = $style_css;
    }

    return $hints;
}

/**
 * Find a scaffolded file by display path.
 *
 * @param list<array<string, mixed>> $written
 * @return array<string, string>|null
 */
function openmira_scaffold_theme_find_written_file(array $written, string $path): ?array
{
    foreach ($written as $file) {
        if (($file['path'] ?? null) !== $path || !is_string($file['content_hash'] ?? null)) {
            continue;
        }

        return [
            'path' => $path,
            'expected_current_hash' => $file['content_hash'],
        ];
    }

    return null;
}

/**
 * Move an existing theme directory aside before a clean scaffold.
 *
 * @return array<string, mixed>|WP_Error
 */
function openmira_scaffold_theme_move_existing_directory(string $theme_dir, string $slug): array|WP_Error
{
    if (!is_dir($theme_dir)) {
        return [];
    }

    $backup_root = OPENMIRA_FILE_BACKUPS_DIR . '/theme-cleanups';
    if (!wp_mkdir_p($backup_root)) {
        return new WP_Error('theme_cleanup_backup_failed', sprintf(
            'Could not create theme cleanup backup directory: %s',
            openmira_display_path($backup_root),
        ));
    }

    $id = gmdate('YmdHis') . '-' . (string) wp_generate_uuid4();
    $backup_dir = trailingslashit($backup_root) . $id . '-' . sanitize_file_name($slug);
    clearstatcache(clear_realpath_cache: true, filename: $theme_dir);
    if (!rename($theme_dir, $backup_dir)) {
        return new WP_Error('theme_cleanup_move_failed', sprintf(
            'Could not move existing theme directory from %s to %s.',
            openmira_display_path($theme_dir),
            openmira_display_path($backup_dir),
        ));
    }
    clearstatcache(clear_realpath_cache: true, filename: $theme_dir);
    clearstatcache(clear_realpath_cache: true, filename: $backup_dir);

    return [
        'id' => $id,
        'operation' => 'scaffold-theme:force-clean',
        'target_path' => openmira_display_path($theme_dir),
        'backup_path' => openmira_display_path($backup_dir),
        'created_at' => gmdate('c'),
        'created_by' => get_current_user_id(),
    ];
}

/**
 * Return theme file map.
 *
 * @return array<string, string>
 */
// @mago-expect lint:excessive-parameter-list
function openmira_scaffold_theme_files(
    string $type,
    string $slug,
    string $name,
    string $description,
    string $design_brief,
    string $text_domain,
    string $parent_slug,
    bool $include_blank_template,
): array {
    if ($type === 'classic') {
        return openmira_scaffold_classic_theme_files($slug, $name, $description, $design_brief, $text_domain);
    }
    if ($type === 'child') {
        return openmira_scaffold_child_theme_files(
            $slug,
            $name,
            $description,
            $design_brief,
            $text_domain,
            $parent_slug,
        );
    }

    return openmira_scaffold_block_theme_files(
        $slug,
        $name,
        $description,
        $design_brief,
        $text_domain,
        $include_blank_template,
    );
}

/**
 * Return block theme starter files.
 *
 * @return array<string, string>
 */
// @mago-expect lint:excessive-parameter-list
function openmira_scaffold_block_theme_files(
    string $slug,
    string $name,
    string $description,
    string $design_brief,
    string $text_domain,
    bool $include_blank_template,
): array {
    $escaped_name = esc_html($name);

    $files = [
        'style.css' => openmira_theme_style_header($name, $description, $text_domain),
        'theme.json' => openmira_block_theme_json($name, $include_blank_template),
        'functions.php' => openmira_block_theme_functions($slug, $text_domain),
        'assets/css/theme.css' => openmira_theme_css($design_brief),
        'templates/index.html' => openmira_block_template_index($slug, $escaped_name, $design_brief),
        'templates/page.html' => openmira_block_template_page(),
        'templates/single.html' => openmira_block_template_single(),
        'parts/header.html' => openmira_block_template_header($escaped_name),
        'parts/footer.html' => openmira_block_template_footer($escaped_name),
        'patterns/hero.php' => openmira_block_theme_hero_pattern($slug, $name, $design_brief),
        'readme.txt' => $name . "\n\n" . $description . "\n\nBuilt with Open Mira.\n",
    ];
    if ($include_blank_template) {
        $files['templates/blank.html'] = openmira_block_template_blank();
    }

    return $files;
}

/**
 * Return classic theme starter files.
 *
 * @return array<string, string>
 */
function openmira_scaffold_classic_theme_files(
    string $slug,
    string $name,
    string $description,
    string $design_brief,
    string $text_domain,
): array {
    return [
        'style.css' =>
            openmira_theme_style_header($name, $description, $text_domain) . "\n" . openmira_theme_css($design_brief),
        'functions.php' => openmira_classic_theme_functions($slug, $text_domain),
        'header.php' => openmira_classic_header_template($name),
        'footer.php' => openmira_classic_footer_template($name),
        'index.php' => openmira_classic_index_template(),
        'page.php' => openmira_classic_page_template(),
        'single.php' => openmira_classic_single_template(),
        'archive.php' => openmira_classic_archive_template(),
        '404.php' => openmira_classic_404_template(),
        'assets/css/theme.css' => openmira_theme_css($design_brief),
        'readme.txt' => $name . "\n\n" . $description . "\n\nBuilt with Open Mira.\n",
    ];
}

/**
 * Return child theme starter files.
 *
 * @return array<string, string>
 */
// @mago-expect lint:excessive-parameter-list
function openmira_scaffold_child_theme_files(
    string $slug,
    string $name,
    string $description,
    string $design_brief,
    string $text_domain,
    string $parent_slug,
): array {
    return [
        'style.css' => openmira_theme_style_header($name, $description, $text_domain, $parent_slug),
        'functions.php' => openmira_child_theme_functions($slug, $parent_slug),
        'assets/css/theme.css' => openmira_theme_css($design_brief),
        'readme.txt' => $name . "\n\n" . $description . "\n\nChild of {$parent_slug}. Built with Open Mira.\n",
    ];
}

/**
 * Write one scaffold file with backup and diff metadata.
 *
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:no-boolean-flag-parameter
function openmira_scaffold_theme_write_file(string $path, string $contents, bool $overwrite): array|WP_Error
{
    $exists = file_exists($path);
    if ($exists && !$overwrite) {
        return new WP_Error('theme_file_exists', sprintf('File already exists: %s', openmira_display_path($path)));
    }

    $parent_dir = dirname($path);
    $directories_created = openmira_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return new WP_Error(
            'theme_directory_failed',
            sprintf('Could not create directory: %s', $parent_dir),
            $directories_created->get_error_data(),
        );
    }

    $old_content = $exists && is_file($path) ? file_get_contents($path) : null;
    if ($old_content === false) {
        return new WP_Error('read_failed', sprintf('Could not read existing file: %s', openmira_display_path($path)));
    }

    $backup = $exists && is_file($path) ? openmira_create_file_backup($path, operation: 'scaffold-theme') : null;
    $bytes = file_put_contents($path, $contents, LOCK_EX);
    if ($bytes === false) {
        return new WP_Error('write_failed', sprintf('Could not write file: %s', openmira_display_path($path)));
    }
    chmod(filename: $path, permissions: 0644);
    $syntax_check = openmira_validate_php_write_or_rollback(
        resolved: $path,
        old_content: $old_content,
        backup: $backup,
        ability: 'openmira/scaffold-theme',
        operation: 'write_theme_file',
        started_at: microtime(as_float: true),
    );
    if (is_wp_error($syntax_check)) {
        return $syntax_check;
    }

    $result = [
        'path' => openmira_display_path($path),
        'created' => !$exists,
        'size' => $bytes,
        'content_hash' => openmira_file_hash_content($contents),
        'diff' => openmira_build_unified_diff($old_content, $contents, $path),
    ];
    if (is_array($backup)) {
        $result['backup'] = $backup;
    }

    return $result;
}

/**
 * Theme stylesheet header.
 */
function openmira_theme_style_header(
    string $name,
    string $description,
    string $text_domain,
    string $template = '',
): string {
    $header = [
        'Theme Name: ' . $name,
        'Theme URI: https://github.com/worldoptimizer/openmira',
        'Author: Open Mira',
        'Author URI: https://github.com/worldoptimizer',
        'Description: ' . $description,
        'Requires at least: 6.9',
        'Tested up to: 6.9',
        'Requires PHP: 8.0',
        'Version: 0.1.0',
        'License: GNU Affero General Public License v3 or later',
        'License URI: https://www.gnu.org/licenses/agpl-3.0.html',
        'Text Domain: ' . $text_domain,
    ];
    if ($template !== '') {
        $header[] = 'Template: ' . $template;
    }

    return "/*\n" . implode("\n", $header) . "\n*/\n";
}

/**
 * Block theme.json.
 */
// @mago-expect lint:halstead
function openmira_block_theme_json(string $name, bool $include_blank_template = false): string
{
    $custom_templates = [
        ['name' => 'page', 'title' => $name . ' Page'],
    ];
    if ($include_blank_template) {
        $custom_templates[] = ['name' => 'blank', 'title' => 'Blank Landing Page'];
    }

    $json = [
        '$schema' => 'https://schemas.wp.org/wp/6.9/theme.json',
        'version' => 3,
        'settings' => [
            'appearanceTools' => true,
            'layout' => [
                'contentSize' => '760px',
                'wideSize' => '1180px',
            ],
            'color' => [
                'palette' => [
                    ['slug' => 'base', 'color' => '#fffaf4', 'name' => 'Base'],
                    ['slug' => 'contrast', 'color' => '#16191f', 'name' => 'Contrast'],
                    ['slug' => 'primary', 'color' => '#3657ff', 'name' => 'Primary'],
                    ['slug' => 'secondary', 'color' => '#e7ecff', 'name' => 'Secondary'],
                    ['slug' => 'muted', 'color' => '#6d7480', 'name' => 'Muted'],
                ],
            ],
            'typography' => [
                'fluid' => true,
                'fontFamilies' => [
                    [
                        'slug' => 'system',
                        'name' => 'System',
                        'fontFamily' => '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
                    ],
                ],
            ],
            'spacing' => [
                'spacingScale' => ['steps' => 0],
                'spacingSizes' => [
                    ['slug' => '30', 'size' => '1rem', 'name' => 'S'],
                    ['slug' => '40', 'size' => '1.5rem', 'name' => 'M'],
                    ['slug' => '50', 'size' => '2.5rem', 'name' => 'L'],
                    ['slug' => '60', 'size' => '4rem', 'name' => 'XL'],
                    ['slug' => '70', 'size' => '6rem', 'name' => '2XL'],
                ],
            ],
        ],
        'styles' => [
            'color' => [
                'background' => 'var:preset|color|base',
                'text' => 'var:preset|color|contrast',
            ],
            'typography' => [
                'fontFamily' => 'var:preset|font-family|system',
                'lineHeight' => '1.55',
            ],
            'elements' => [
                'button' => [
                    'border' => ['radius' => '999px'],
                    'color' => [
                        'background' => 'var:preset|color|primary',
                        'text' => 'var:preset|color|base',
                    ],
                    'spacing' => [
                        'padding' => [
                            'top' => '.85rem',
                            'right' => '1.25rem',
                            'bottom' => '.85rem',
                            'left' => '1.25rem',
                        ],
                    ],
                ],
            ],
        ],
        'templateParts' => [
            ['name' => 'header', 'title' => 'Header', 'area' => 'header'],
            ['name' => 'footer', 'title' => 'Footer', 'area' => 'footer'],
        ],
        'customTemplates' => $custom_templates,
    ];
    $encoded = wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded . "\n" : "{}\n";
}

/**
 * Block theme functions.
 */
function openmira_block_theme_functions(string $slug, string $text_domain): string
{
    return <<<PHP
        <?php

        declare(strict_types=1);

        add_action('after_setup_theme', static function (): void {
            load_theme_textdomain('{$text_domain}', get_template_directory() . '/languages');
        });

        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('{$slug}-theme', get_template_directory_uri() . '/assets/css/theme.css', [], wp_get_theme()->get('Version'));
        });

        PHP;
}

/**
 * Shared CSS starter.
 */
function openmira_theme_css(string $design_brief): string
{
    return <<<CSS
        /*
        Design brief: {$design_brief}
        */

        body {
            text-rendering: optimizeLegibility;
        }

        .wp-site-blocks {
            overflow-x: clip;
        }

        .openmira-shell {
            max-width: 1180px;
            margin-inline: auto;
            padding-inline: clamp(1.25rem, 4vw, 3rem);
        }

        .openmira-card {
            border: 1px solid color-mix(in srgb, currentColor 12%, transparent);
            border-radius: 28px;
            box-shadow: 0 24px 80px color-mix(in srgb, #16191f 10%, transparent);
        }

        .openmira-gradient {
            background:
                radial-gradient(circle at 20% 0%, color-mix(in srgb, var(--wp--preset--color--primary) 24%, transparent), transparent 32rem),
                linear-gradient(135deg, var(--wp--preset--color--base), var(--wp--preset--color--secondary));
        }

        CSS;
}

/**
 * Block index template.
 */
function openmira_block_template_index(string $slug, string $site_name, string $design_brief): string
{
    return <<<HTML
        <!-- wp:template-part {"slug":"header","tagName":"header"} /-->

        <!-- wp:group {"tagName":"main","className":"openmira-gradient","layout":{"type":"constrained"}} -->
        <main class="wp-block-group openmira-gradient">
        <!-- wp:group {"align":"wide","className":"openmira-shell","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
        <div class="wp-block-group alignwide openmira-shell" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
        <!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.16em","fontStyle":"normal","fontWeight":"700"}},"textColor":"primary"} -->
        <p class="has-primary-color has-text-color" style="font-style:normal;font-weight:700;letter-spacing:0.16em;text-transform:uppercase">Open Mira Theme Build</p>
        <!-- /wp:paragraph -->

        <!-- wp:heading {"level":1,"fontSize":"xx-large"} -->
        <h1 class="wp-block-heading has-xx-large-font-size">{$site_name} is ready for a real design pass.</h1>
        <!-- /wp:heading -->

        <!-- wp:paragraph {"fontSize":"large"} -->
        <p class="has-large-font-size">{$design_brief}</p>
        <!-- /wp:paragraph -->

        <!-- wp:buttons -->
        <div class="wp-block-buttons"><!-- wp:button -->
        <div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/wp-admin/customize.php">Customize theme</a></div>
        <!-- /wp:button -->

        <!-- wp:button {"className":"is-style-outline"} -->
        <div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/wp-admin/site-editor.php">Open Site Editor</a></div>
        <!-- /wp:button --></div>
        <!-- /wp:buttons -->
        </div>
        <!-- /wp:group -->

        <!-- wp:pattern {"slug":"{$slug}/hero"} /-->

        <!-- wp:query {"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false},"align":"wide","className":"openmira-shell"} -->
        <div class="wp-block-query alignwide openmira-shell"><!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
        <!-- wp:group {"className":"openmira-card","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
        <div class="wp-block-group openmira-card has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /--></div>
        <!-- /wp:group -->
        <!-- /wp:post-template --></div>
        <!-- /wp:query -->
        </main>
        <!-- /wp:group -->

        <!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
        HTML;
}

function openmira_block_template_page(): string
{
    return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n<!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /-->\n<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
}

function openmira_block_template_blank(): string
{
    return "<!-- wp:group {\"tagName\":\"main\",\"align\":\"full\",\"layout\":{\"type\":\"default\"}} -->\n<main class=\"wp-block-group alignfull\"><!-- wp:post-content {\"layout\":{\"type\":\"default\"}} /--></main>\n<!-- /wp:group -->\n";
}

function openmira_block_template_single(): string
{
    return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"type\":\"constrained\"}} --><main class=\"wp-block-group\"><!-- wp:post-title {\"level\":1} /--><!-- wp:post-date /--><!-- wp:post-content /--></main><!-- /wp:group -->\n<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
}

function openmira_block_template_header(string $site_name): string
{
    return <<<HTML
        <!-- wp:group {"className":"openmira-shell","style":{"spacing":{"padding":{"top":"1.25rem","bottom":"1.25rem"}}},"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
        <div class="wp-block-group openmira-shell" style="padding-top:1.25rem;padding-bottom:1.25rem"><!-- wp:site-title {"level":0} /--><!-- wp:navigation {"overlayMenu":"mobile"} /--></div>
        <!-- /wp:group -->
        HTML;
}

function openmira_block_template_footer(string $site_name): string
{
    return <<<HTML
        <!-- wp:group {"className":"openmira-shell","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
        <div class="wp-block-group openmira-shell" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:paragraph -->
        <p>© {$site_name}</p>
        <!-- /wp:paragraph --><!-- wp:paragraph -->
        <p>Built with Open Mira.</p>
        <!-- /wp:paragraph --></div>
        <!-- /wp:group -->
        HTML;
}

function openmira_block_theme_hero_pattern(string $slug, string $name, string $design_brief): string
{
    return <<<PHP
        <?php
        /**
         * Title: Editorial Hero
         * Slug: {$slug}/hero
         * Categories: featured, call-to-action
         */
        ?>
        <!-- wp:group {"align":"wide","className":"openmira-shell","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"grid","minimumColumnWidth":"22rem"}} -->
        <div class="wp-block-group alignwide openmira-shell" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
        <!-- wp:group {"className":"openmira-card","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
        <div class="wp-block-group openmira-card has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
        <!-- wp:heading -->
        <h2 class="wp-block-heading">{$name}</h2>
        <!-- /wp:heading -->
        <!-- wp:paragraph -->
        <p>{$design_brief}</p>
        <!-- /wp:paragraph -->
        </div>
        <!-- /wp:group -->
        <!-- wp:group {"className":"openmira-card","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"}}},"backgroundColor":"secondary","layout":{"type":"constrained"}} -->
        <div class="wp-block-group openmira-card has-secondary-background-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
        <!-- wp:list -->
        <ul><li>Editable block theme templates</li><li>Global palette and spacing tokens</li><li>Starter pattern ready for iteration</li></ul>
        <!-- /wp:list -->
        </div>
        <!-- /wp:group -->
        </div>
        <!-- /wp:group -->
        PHP;
}

function openmira_classic_theme_functions(string $slug, string $text_domain): string
{
    return <<<PHP
        <?php

        declare(strict_types=1);

        add_action('after_setup_theme', static function (): void {
            load_theme_textdomain('{$text_domain}', get_template_directory() . '/languages');
            add_theme_support('title-tag');
            add_theme_support('post-thumbnails');
        });

        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('{$slug}-theme', get_template_directory_uri() . '/assets/css/theme.css', [], wp_get_theme()->get('Version'));
        });

        PHP;
}

function openmira_child_theme_functions(string $slug, string $parent_slug): string
{
    return <<<PHP
        <?php

        declare(strict_types=1);

        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('{$parent_slug}-parent', get_template_directory_uri() . '/style.css', [], wp_get_theme(get_template())->get('Version'));
            wp_enqueue_style('{$slug}-child', get_stylesheet_directory_uri() . '/assets/css/theme.css', ['{$parent_slug}-parent'], wp_get_theme()->get('Version'));
        });

        PHP;
}

function openmira_classic_header_template(string $name): string
{
    return <<<PHP
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php wp_head(); ?>
        </head>
        <body <?php body_class(); ?>>
        <?php wp_body_open(); ?>
        <header class="openmira-shell site-header">
            <a class="site-title" href="<?php echo esc_url(home_url('/')); ?>">{$name}</a>
            <?php wp_nav_menu(['theme_location' => 'primary', 'fallback_cb' => false]); ?>
        </header>
        PHP;
}

function openmira_classic_footer_template(string $name): string
{
    return <<<PHP
        <footer class="openmira-shell site-footer">
            <p>&copy; <?php echo esc_html(date('Y')); ?> {$name}. Built with Open Mira.</p>
        </footer>
        <?php wp_footer(); ?>
        </body>
        </html>
        PHP;
}

function openmira_classic_index_template(): string
{
    return "<?php get_header(); ?>\n<main class=\"openmira-shell content-loop\">\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n<article <?php post_class('openmira-card'); ?>>\n<h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n<?php the_excerpt(); ?>\n</article>\n<?php endwhile; else : ?>\n<p><?php esc_html_e('No posts found.', 'open-mira'); ?></p>\n<?php endif; ?>\n</main>\n<?php get_footer(); ?>\n";
}

function openmira_classic_page_template(): string
{
    return "<?php get_header(); ?>\n<main class=\"openmira-shell\">\n<?php while (have_posts()) : the_post(); ?>\n<h1><?php the_title(); ?></h1>\n<?php the_content(); ?>\n<?php endwhile; ?>\n</main>\n<?php get_footer(); ?>\n";
}

function openmira_classic_single_template(): string
{
    return "<?php get_header(); ?>\n<main class=\"openmira-shell\">\n<?php while (have_posts()) : the_post(); ?>\n<article <?php post_class(); ?>>\n<h1><?php the_title(); ?></h1>\n<?php the_content(); ?>\n</article>\n<?php endwhile; ?>\n</main>\n<?php get_footer(); ?>\n";
}

function openmira_classic_archive_template(): string
{
    return "<?php get_header(); ?>\n<main class=\"openmira-shell\">\n<h1><?php the_archive_title(); ?></h1>\n<?php get_template_part('index'); ?>\n</main>\n<?php get_footer(); ?>\n";
}

function openmira_classic_404_template(): string
{
    return "<?php get_header(); ?>\n<main class=\"openmira-shell\">\n<h1><?php esc_html_e('Not found', 'open-mira'); ?></h1>\n<p><?php esc_html_e('The page could not be found.', 'open-mira'); ?></p>\n</main>\n<?php get_footer(); ?>\n";
}
