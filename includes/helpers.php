<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Shared helper functions for Novamira.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolve a filesystem path, ensuring it stays within the allowed base directory.
 *
 * @param string $path       The path to resolve. Relative paths are prepended with ABSPATH.
 * @param bool   $must_exist Whether the path must already exist.
 * @return string|WP_Error   The resolved absolute path, or WP_Error on failure.
 */
function novamira_resolve_path($path, $must_exist = false)
{
    // Prepend ABSPATH to relative paths.
    if (!str_starts_with($path, '/') && !str_starts_with($path, '\\')) {
        $path = ABSPATH . $path;
    }

    /**
     * Filter the base directory for filesystem operations.
     * Return false to disable the base directory restriction entirely.
     *
     * @param string $base_dir The base directory. Defaults to ABSPATH.
     */
    /** @var string|false $base_dir */
    $base_dir = apply_filters('novamira_filesystem_base_dir', ABSPATH);

    // Resolve path that may not exist yet via parent directory.
    $resolved_parent = realpath(dirname($path));
    $resolved = $resolved_parent !== false ? $resolved_parent . DIRECTORY_SEPARATOR . basename($path) : $path;

    // For paths that must exist, override with realpath.
    if ($must_exist) {
        $resolved = realpath($path);
        if ($resolved === false) {
            return new WP_Error('path_not_found', sprintf(__('Path does not exist: %s', domain: 'novamira'), $path));
        }
    }

    // Enforce base directory restriction.
    if ($base_dir !== false) {
        $real_base = realpath($base_dir);
        if ($real_base === false) {
            $real_base = rtrim($base_dir, characters: '/\\');
        }

        if (!str_starts_with($resolved, $real_base)) {
            return new WP_Error('path_outside_base', sprintf(
                __('Path "%s" is outside the allowed base directory "%s".', domain: 'novamira'),
                $resolved,
                $real_base,
            ));
        }
    }

    return $resolved;
}

/**
 * Get the sandbox directory path for AI-written PHP plugins.
 *
 * @param bool $ensure_exists Whether to create the directory if it doesn't exist.
 * @return string Absolute path to the sandbox directory (with trailing slash).
 */
function novamira_get_sandbox_dir($ensure_exists = false)
{
    if ($ensure_exists && !is_dir(NOVAMIRA_SANDBOX_DIR)) {
        wp_mkdir_p(NOVAMIRA_SANDBOX_DIR);
    }

    return NOVAMIRA_SANDBOX_DIR;
}

/**
 * Validate that a resolved path is inside the sandbox directory.
 *
 * @param string $resolved The resolved absolute path to check.
 * @return true|WP_Error True if inside the sandbox, WP_Error otherwise.
 */
function novamira_validate_sandbox_path($resolved)
{
    $sandbox_dir = novamira_get_sandbox_dir();
    $real_sandbox = realpath($sandbox_dir);
    if ($real_sandbox === false) {
        return new WP_Error('sandbox_not_found', __('The sandbox directory does not exist.', domain: 'novamira'));
    }

    $real_resolved = realpath($resolved);
    if ($real_resolved === false) {
        $real_resolved = $resolved;
    }

    if (!str_starts_with($real_resolved, $real_sandbox . DIRECTORY_SEPARATOR)) {
        return new WP_Error('outside_sandbox', sprintf(
            /* translators: %s: sandbox directory path */
            __('Only files inside the sandbox (%s) can be modified.', domain: 'novamira'),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check whether a filename ends with the ".disabled" suffix.
 *
 * @param string $path File path to check.
 * @return bool
 */
function novamira_is_disabled_file($path)
{
    return str_ends_with($path, '.disabled');
}

/**
 * Check whether the AI abilities are enabled via the settings option.
 *
 * @return bool
 */
function novamira_is_enabled()
{
    /** @var mixed $value */
    $value = get_option('novamira_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    // Abilities are locked to the domain they were enabled on.
    /** @var string $locked_domain */
    $locked_domain = get_option('novamira_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain === $current_domain;
}

/**
 * Check whether abilities are nominally enabled but inactive due to a domain mismatch.
 *
 * @return bool
 */
function novamira_is_domain_mismatch()
{
    /** @var mixed $value */
    $value = get_option('novamira_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    /** @var string $locked_domain */
    $locked_domain = get_option('novamira_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain !== $current_domain;
}

/**
 * Build a combined date/time format string from WordPress settings.
 *
 * Falls back to 'Y-m-d H:i:s' if either format is empty.
 *
 * @param string $fallback Optional fallback format.
 * @return string
 */
function novamira_get_datetime_format($fallback = 'Y-m-d H:i:s')
{
    $date_format = (string) get_option('date_format');
    $time_format = (string) get_option('time_format');

    if ($date_format === '' || $time_format === '') {
        return $fallback;
    }

    return $date_format . ' ' . $time_format;
}

/**
 * Permission callback: requires manage_options capability.
 *
 * @return bool
 */
function novamira_permission_callback()
{
    return current_user_can('manage_options');
}

/**
 * Detect active languages from multilingual plugins (WPML, Polylang, TranslatePress).
 *
 * @return array{plugin: string, languages: string[]}|null Plugin name and language codes, or null if no multilingual plugin is active.
 */
function novamira_get_active_languages()
{
    // WPML.
    if (function_exists('icl_get_languages')) {
        /** @var array<string, array{language_code: string}>|false $wpml_languages */
        $wpml_languages = icl_get_languages('skip_missing=0');
        if (is_array($wpml_languages)) {
            return ['plugin' => 'WPML', 'languages' => array_column($wpml_languages, 'language_code')];
        }
    }

    // Polylang.
    if (function_exists('pll_languages_list')) {
        /** @var string[]|false $languages */
        $languages = pll_languages_list();
        if (is_array($languages)) {
            return ['plugin' => 'Polylang', 'languages' => $languages];
        }
    }

    // TranslatePress.
    if (class_exists('TRP_Translate_Press')) {
        /** @var array{translation-languages?: string[]} $trp_settings */
        $trp_settings = get_option('trp_settings', default_value: []);
        return ['plugin' => 'TranslatePress', 'languages' => $trp_settings['translation-languages'] ?? []];
    }

    return null;
}

/**
 * Build the MCP server instructions sent to AI agents during initialization.
 *
 * Includes environment info (PHP/WP versions, plugins) and guidance on using
 * WordPress-native features instead of hardcoding data in PHP.
 *
 * @return string
 */
function novamira_build_server_instructions()
{
    $lines = [
        'Novamira gives you unrestricted control over this WordPress installation.',
        '',
        '## Environment',
        '',
        'WordPress ' . get_bloginfo('version') . ' — PHP ' . PHP_VERSION . ' — Locale: ' . get_locale(),
    ];

    // Detect active languages from multilingual plugins.
    $multilingual = novamira_get_active_languages();
    if ($multilingual !== null && $multilingual['languages'] !== []) {
        $lines[] = 'Multilingual (' . $multilingual['plugin'] . '): ' . implode(', ', $multilingual['languages']);
    }

    $lines[] = '';

    if (function_exists('get_plugins')) {
        /** @var array<string, array{Name?: string, Version?: string}> $all_plugins */
        $all_plugins = get_plugins();
        if ($all_plugins !== []) {
            $lines[] = 'Installed plugins:';
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                $name = $plugin_data['Name'] ?? $plugin_file;
                $version = $plugin_data['Version'] ?? '';
                $version_suffix = $version !== '' ? ' v' . $version : '';
                $active = is_plugin_active($plugin_file) ? 'active' : 'inactive';
                $lines[] = '- ' . $name . $version_suffix . ' (' . $active . ')';
            }
            $lines[] = '';
        }
    }

    $lines = array_merge($lines, [
        '## WordPress-native development',
        '',
        'IMPORTANT: Prefer WordPress-native features to store and manage data.',
        'Do not hardcode content in PHP arrays when WordPress has a better mechanism:',
        '- Custom post types (register_post_type) for structured content',
        '- Taxonomies (register_taxonomy) for categorization',
        '- Post meta / custom fields (update_post_meta) for additional data on posts',
        '- Options API (update_option) for settings and configuration',
        '- Custom database tables via $wpdb only when the above are insufficient',
        '',
        'Take advantage of active plugins. For example if Advanced Custom Fields (ACF)',
        'is installed, use ACF field groups and get_field()/update_field() instead of',
        'raw post meta. If WooCommerce is active, use its product post type and APIs',
        'rather than building a custom shop from scratch.',
        '',
        'Use WordPress hooks (actions/filters), template hierarchy, and REST API',
        'conventions. Write code that integrates with WordPress, not code that ignores it.',
    ]);

    return implode("\n", $lines);
}

/**
 * Render the branded admin header with logo and background color.
 */
function novamira_render_admin_header(): void
{ ?>
    <style>
        .novamira-admin-header-wrap {
            background: #000;
            margin: -1px 0 0 -20px;
            padding: 20px 20px 20px 22px;
        }
        .novamira-admin-header {
            margin: 0 auto;
            display: flex;
            align-items: center;
        }
        .novamira-admin-header img {
            max-height: 40px;
        }
        @media screen and (max-width: 782px) {
            .novamira-admin-header-wrap {
                margin: -1px 0 0 -10px;
                padding: 15px;
            }
            .novamira-admin-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
    <div class="novamira-admin-header-wrap">
        <div class="novamira-admin-header">
            <img src="<?php echo esc_url((string) NOVAMIRA_PLUGIN_URL . 'assets/novamira_logo.svg'); ?>" alt="Novamira" width="200" height="40">
        </div>
    </div>
    <?php }
