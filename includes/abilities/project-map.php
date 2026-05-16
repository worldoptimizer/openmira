<?php

declare(strict_types=1);

/**
 * Ability: WordPress-aware project map.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/get-project-map', [
    'label' => __('Get Project Map', domain: 'open-mira'),
    'description' => __(
        'Returns a WordPress-aware project map: active theme, plugins, build tooling, writable locations, block files, templates, patterns, and project rules.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'include_files' => [
                'type' => 'boolean',
                'description' => 'Include role-grouped file inventories.',
                'default' => false,
            ],
            'fields' => [
                'type' => 'array',
                'description' => 'Optional top-level sections to return. Use this to keep context small.',
                'items' => [
                    'type' => 'string',
                    'enum' => [
                        'instructions',
                        'site',
                        'theme',
                        'plugins',
                        'build_tools',
                        'writable_locations',
                        'sandbox',
                        'files',
                        'rules',
                        'limits',
                    ],
                ],
            ],
            'sections' => [
                'type' => 'array',
                'description' => 'Alias for fields.',
                'items' => [
                    'type' => 'string',
                    'enum' => [
                        'instructions',
                        'site',
                        'theme',
                        'plugins',
                        'build_tools',
                        'writable_locations',
                        'sandbox',
                        'files',
                        'rules',
                        'limits',
                    ],
                ],
            ],
            'include_inactive_plugins' => [
                'type' => 'boolean',
                'description' => 'Include inactive plugins in the plugin inventory.',
                'default' => false,
            ],
            'max_files' => [
                'type' => 'integer',
                'description' => 'Maximum files to include per inventory group.',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 1000,
            ],
            'focus_path' => [
                'type' => 'string',
                'description' => 'Optional path the agent is currently editing; matching files are highlighted in the map.',
                'default' => '',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'instructions' => ['type' => 'string'],
            'site' => ['type' => 'object'],
            'theme' => ['type' => 'object'],
            'plugins' => ['type' => 'object'],
            'build_tools' => ['type' => 'object'],
            'writable_locations' => ['type' => 'array', 'items' => ['type' => 'object']],
            'files' => ['type' => 'object'],
            'rules' => ['type' => 'object'],
            'limits' => ['type' => 'object'],
        ],
        'required' => ['instructions', 'limits'],
    ],
    'execute_callback' => 'openmira_get_project_map',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
        'annotations' => [
            'instructions' => 'Use before creating or editing WordPress themes, plugins, blocks, templates, patterns, CSS, JS, or PHP.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/project-map-summary-resource', [
    'label' => __('Project Map Summary', domain: 'open-mira'),
    'description' => __(
        'Cached MCP resource containing the bounded WordPress project map summary. Read this before calling get-project-map with heavier fields.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_get_project_map_resource',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => [
            'public' => false,
            'type' => 'resource',
            'uri' => 'openmira://project-map/summary',
            'mimeType' => 'application/json',
        ],
        'annotations' => [
            'instructions' => 'Read-only bounded project map resource. Use get-project-map fields/include_files only when more detail is needed.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

add_filter(
    'mcp_adapter_default_server_config',
    callback: 'openmira_add_project_map_resources_to_mcp_config',
    priority: 20,
);

/**
 * Return a WordPress-aware project map for IDE-style agents.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function openmira_get_project_map(array $input = []): array
{
    $fields = openmira_project_map_normalize_fields($input['fields'] ?? $input['sections'] ?? []);
    $include_files = ($input['include_files'] ?? false) === true || in_array('files', $fields, strict: true);
    $include_inactive_plugins = ($input['include_inactive_plugins'] ?? false) === true;
    $max_files = max(1, min(1000, (int) ($input['max_files'] ?? 50)));
    $focus_path = openmira_project_map_normalize_focus_path((string) ($input['focus_path'] ?? ''));

    $theme = openmira_project_map_theme($max_files, $focus_path, $include_files);
    $plugins = openmira_project_map_plugins($include_inactive_plugins, $max_files, $focus_path, $include_files);
    $files = $include_files ? openmira_project_map_files($theme, $plugins, $max_files, $focus_path) : [];

    $map = [
        'instructions' => implode("\n", [
            'Open Mira project map rules:',
            '- Use this as the first read before code edits; it identifies the active theme, plugin entry points, block files, templates, and safe write targets.',
            '- Prefer theme/plugin/block-specific files over generic filesystem edits.',
            '- Use sandbox paths for experimental PHP until the user asks to graduate code into a real theme or plugin.',
            '- Read a target file before editing it, and return diffs for destructive changes.',
            '- Default output is intentionally bounded. Request fields/include_files/max_files only when needed.',
        ]),
        'site' => openmira_project_map_site(),
        'theme' => $theme,
        'plugins' => $plugins,
        'build_tools' => openmira_project_map_build_tools($theme, $plugins),
        'writable_locations' => openmira_project_map_writable_locations($theme),
        'files' => $files,
        'rules' => openmira_project_map_rules(),
        'limits' => [
            'include_files' => $include_files,
            'include_inactive_plugins' => $include_inactive_plugins,
            'max_files_per_group' => $max_files,
            'focus_path' => $focus_path,
            'available_fields' => openmira_project_map_field_names(),
            'returned_fields' => $fields === [] ? openmira_project_map_field_names() : $fields,
        ],
    ];

    return openmira_project_map_filter_fields($map, $fields);
}

/**
 * Return a bounded project-map payload for MCP Resource reads.
 *
 * @return list<array<string, string>>
 */
function openmira_get_project_map_resource(): array
{
    $map = openmira_get_project_map([
        'include_files' => false,
        'include_inactive_plugins' => false,
        'max_files' => 25,
        'fields' => [
            'instructions',
            'site',
            'theme',
            'plugins',
            'build_tools',
            'writable_locations',
            'rules',
            'limits',
        ],
    ]);

    $json = wp_json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    return [[
        'uri' => 'openmira://project-map/summary',
        'text' => $json,
        'mimeType' => 'application/json',
    ]];
}

/**
 * Add the project-map summary as a direct MCP resource.
 */
function openmira_add_project_map_resources_to_mcp_config(mixed $config): mixed
{
    if (!is_array($config)) {
        return $config;
    }

    // @mago-expect analysis:mixed-assignment
    $resources = $config['resources'] ?? [];
    if (!is_array($resources)) {
        $resources = [];
    }

    $config['resources'] = array_merge($resources, openmira_get_project_map_mcp_resources());
    return $config;
}

/**
 * Build direct MCP resources for project-map context.
 *
 * @return list<object>
 */
function openmira_get_project_map_mcp_resources(): array
{
    if (!class_exists(\WP\MCP\Domain\Resources\McpResource::class)) {
        return [];
    }

    $resource = \WP\MCP\Domain\Resources\McpResource::fromArray([
        'uri' => 'openmira://project-map/summary',
        'name' => 'openmira-project-map-summary',
        'title' => 'Project Map Summary',
        'description' => 'Bounded WordPress project map summary. Read this before heavier get-project-map calls.',
        'mimeType' => 'application/json',
        'handler' => static fn(): array => openmira_get_project_map_resource(),
        'permission' => static fn(): bool => openmira_permission_bool('openmira/get-project-map'),
    ]);

    if (is_wp_error($resource)) {
        return [];
    }

    return [$resource];
}

/**
 * Return known project-map top-level fields.
 *
 * @return list<string>
 */
function openmira_project_map_field_names(): array
{
    return [
        'instructions',
        'site',
        'theme',
        'plugins',
        'build_tools',
        'writable_locations',
        'files',
        'rules',
        'limits',
    ];
}

/**
 * Normalize requested project-map fields.
 *
 * @return list<string>
 */
function openmira_project_map_normalize_fields(mixed $fields): array
{
    if (!is_array($fields)) {
        return [];
    }

    $allowed = openmira_project_map_field_names();
    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($fields as $field) {
        if (!is_string($field)) {
            continue;
        }
        $field = sanitize_key($field);
        if ($field === 'sandbox') {
            $field = 'writable_locations';
        }
        if ($field !== '' && in_array($field, $allowed, strict: true)) {
            $normalized[] = $field;
        }
    }

    return array_values(array_unique($normalized));
}

/**
 * Filter a project map to requested top-level fields.
 *
 * @param array<string, mixed> $map
 * @param list<string> $fields
 * @return array<string, mixed>
 */
function openmira_project_map_filter_fields(array $map, array $fields): array
{
    if ($fields === []) {
        return $map;
    }

    $filtered = [
        'instructions' => $map['instructions'],
        'limits' => $map['limits'],
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $map)) {
            $filtered[$field] = $map[$field];
        }
    }

    return $filtered;
}

/**
 * Return site/runtime metadata.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_site(): array
{
    return [
        'name' => get_bloginfo('name'),
        'url' => home_url('/'),
        'admin_url' => admin_url(),
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'locale' => get_locale(),
        'timezone' => wp_timezone_string(),
        'multisite' => is_multisite(),
        'content_dir' => openmira_project_map_relative_path(WP_CONTENT_DIR),
        'plugin_dir' => openmira_project_map_relative_path(WP_PLUGIN_DIR),
        'mu_plugin_dir' => openmira_project_map_mu_plugin_dir(),
        'sandbox_dir' => openmira_project_map_relative_path(OPENMIRA_SANDBOX_DIR),
    ];
}

/**
 * Return active theme information.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_theme(int $max_files, string $focus_path, bool $include_files): array
{
    $theme = wp_get_theme();
    $stylesheet = get_stylesheet();
    $template = get_template();
    $stylesheet_dir = get_stylesheet_directory();
    $template_dir = get_template_directory();
    $is_child = $stylesheet !== $template;
    $is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();

    $data = [
        'name' => $theme->get('Name'),
        'version' => $theme->get('Version'),
        'stylesheet' => $stylesheet,
        'template' => $template,
        'text_domain' => $theme->get('TextDomain'),
        'is_child_theme' => $is_child,
        'is_block_theme' => $is_block_theme,
        'stylesheet_dir' => openmira_project_map_relative_path($stylesheet_dir),
        'template_dir' => openmira_project_map_relative_path($template_dir),
        'parent' => null,
        'supports' => [
            'block_templates' => $is_block_theme || is_dir($stylesheet_dir . '/templates'),
            'theme_json' => file_exists($stylesheet_dir . '/theme.json') || file_exists($template_dir . '/theme.json'),
            'patterns_dir' => is_dir($stylesheet_dir . '/patterns') || is_dir($template_dir . '/patterns'),
            'template_parts_dir' => is_dir($stylesheet_dir . '/parts') || is_dir($template_dir . '/parts'),
        ],
    ];

    if ($is_child) {
        $parent = $theme->parent();
        $data['parent'] = $parent instanceof WP_Theme
            ? [
                'name' => $parent->get('Name'),
                'version' => $parent->get('Version'),
                'stylesheet' => $parent->get_stylesheet(),
                'dir' => openmira_project_map_relative_path($template_dir),
            ]
            : [
                'stylesheet' => $template,
                'dir' => openmira_project_map_relative_path($template_dir),
            ];
    }

    if ($include_files) {
        $data['files'] = openmira_project_map_theme_files($stylesheet_dir, $template_dir, $max_files, $focus_path);
    }

    return $data;
}

/**
 * Return active/inactive plugin metadata.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_plugins(
    bool $include_inactive_plugins,
    int $max_files,
    string $focus_path,
    bool $include_files,
): array {
    openmira_project_map_load_plugin_functions();

    /** @var array<string, array<string, string>> $all_plugins */
    $all_plugins = function_exists('get_plugins') ? get_plugins() : [];
    $active_plugins = function_exists('wp_get_active_and_valid_plugins') ? wp_get_active_and_valid_plugins() : [];
    $active_plugin_files = [];
    foreach ($active_plugins as $plugin_path) {
        $active_plugin_files[] = plugin_basename($plugin_path);
    }

    $items = [];
    foreach ($all_plugins as $plugin_file => $plugin_data) {
        $active =
            in_array($plugin_file, $active_plugin_files, strict: true)
            || function_exists('is_plugin_active') && is_plugin_active($plugin_file);
        if (!$active && !$include_inactive_plugins) {
            continue;
        }

        $plugin_abs = WP_PLUGIN_DIR . '/' . $plugin_file;
        $plugin_dir = dirname($plugin_abs);
        $plugin = [
            'file' => $plugin_file,
            'name' => $plugin_data['Name'] ?? $plugin_file,
            'version' => $plugin_data['Version'] ?? '',
            'text_domain' => $plugin_data['TextDomain'] ?? '',
            'active' => $active,
            'entry' => openmira_project_map_relative_path($plugin_abs),
            'dir' => openmira_project_map_relative_path($plugin_dir),
        ];

        if ($include_files && $active) {
            $plugin['files'] = openmira_project_map_plugin_files($plugin_dir, $max_files, $focus_path);
        }

        $items[] = $plugin;
    }

    usort($items, static fn(array $first, array $second): int => strcmp($first['name'], $second['name']));

    return [
        'active_count' => count(array_filter($items, static fn(array $item): bool => $item['active'] === true)),
        'included_count' => count($items),
        'items' => $items,
        'mu_plugins' => openmira_project_map_mu_plugins(),
        'sandbox_plugins' => openmira_project_map_sandbox_plugins($max_files),
    ];
}

/**
 * Return a project-wide role-grouped file inventory.
 *
 * @param array<string, mixed> $theme
 * @param array<string, mixed> $plugins
 * @return array<string, mixed>
 */
function openmira_project_map_files(array $theme, array $plugins, int $max_files, string $focus_path): array
{
    $theme_files = is_array($theme['files'] ?? null) ? $theme['files'] : [];
    $plugin_items = openmira_project_map_array_list($plugins['items'] ?? []);

    $block_json = [];
    foreach (openmira_project_map_array_list($theme_files['block_json'] ?? []) as $file) {
        $block_json[] = $file;
    }
    foreach ($plugin_items as $plugin) {
        if (!is_array($plugin['files'] ?? null)) {
            continue;
        }
        foreach (openmira_project_map_array_list($plugin['files']['block_json'] ?? []) as $file) {
            $block_json[] = $file;
        }
    }

    return [
        'theme' => $theme_files,
        'block_json' => array_slice(
            openmira_project_map_sort_files($block_json, $focus_path),
            offset: 0,
            length: $max_files,
        ),
        'openmira_rules_candidates' => openmira_project_map_rules_candidates(),
    ];
}

/**
 * Load plugin admin functions when needed.
 */
function openmira_project_map_load_plugin_functions(): void
{
    if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
}

/**
 * Return theme files grouped by WordPress role.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_theme_files(
    string $stylesheet_dir,
    string $template_dir,
    int $max_files,
    string $focus_path,
): array {
    $dirs = [$stylesheet_dir];
    if ($template_dir !== $stylesheet_dir) {
        $dirs[] = $template_dir;
    }

    $files = openmira_project_map_collect_files($dirs, $max_files * 6, $focus_path);

    return [
        'php_templates' => openmira_project_map_filter_files(
            files: $files,
            pattern: '/\\.(php)$/',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'block_templates' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/(templates|parts)/.+\\.html$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'patterns' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/patterns/.+\\.php$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'theme_json' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/theme\\.json$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'block_json' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/block\\.json$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'assets' => openmira_project_map_filter_files(
            files: $files,
            pattern: '/\\.(css|scss|sass|js|mjs|ts|tsx|jsx)$/',
            limit: $max_files,
            focus_path: $focus_path,
        ),
    ];
}

/**
 * Return active plugin files grouped by WordPress role.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_plugin_files(string $plugin_dir, int $max_files, string $focus_path): array
{
    $files = openmira_project_map_collect_files([$plugin_dir], $max_files * 5, $focus_path);

    return [
        'entry_candidates' => openmira_project_map_filter_files(
            files: $files,
            pattern: '/\\.php$/',
            limit: 20,
            focus_path: $focus_path,
        ),
        'block_json' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/block\\.json$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'php' => openmira_project_map_filter_files(
            files: $files,
            pattern: '/\\.php$/',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'assets' => openmira_project_map_filter_files(
            files: $files,
            pattern: '/\\.(css|scss|sass|js|mjs|ts|tsx|jsx)$/',
            limit: $max_files,
            focus_path: $focus_path,
        ),
        'build_config' => openmira_project_map_filter_files(
            files: $files,
            pattern: '#/(package\\.json|composer\\.json|webpack\\.config\\.[cm]?js|vite\\.config\\.[cm]?[jt]s|tsconfig\\.json)$#',
            limit: $max_files,
            focus_path: $focus_path,
        ),
    ];
}

/**
 * Return mu-plugin inventory.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_mu_plugins(): array
{
    if (!function_exists('get_mu_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    /** @var array<string, array<string, string>> $mu_plugins */
    $mu_plugins = function_exists('get_mu_plugins') ? get_mu_plugins() : [];
    $items = [];
    foreach ($mu_plugins as $plugin_file => $plugin_data) {
        $items[] = [
            'file' => $plugin_file,
            'name' => $plugin_data['Name'] ?? $plugin_file,
            'version' => $plugin_data['Version'] ?? '',
            'entry' => openmira_project_map_mu_plugin_dir() !== ''
                ? openmira_project_map_relative_path(openmira_project_map_mu_plugin_dir() . '/' . $plugin_file)
                : $plugin_file,
        ];
    }

    return $items;
}

/**
 * Return sandbox plugin inventory.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_sandbox_plugins(int $max_files): array
{
    if (!is_dir(OPENMIRA_SANDBOX_DIR)) {
        return [];
    }

    return openmira_project_map_filter_files(
        files: openmira_project_map_collect_files(
            directories: [OPENMIRA_SANDBOX_DIR],
            limit: $max_files,
            focus_path: '',
        ),
        pattern: '/\\.php(\\.disabled)?$/',
        limit: $max_files,
        focus_path: '',
    );
}

/**
 * Return build-tool detection across root/theme/plugin locations.
 *
 * @param array<string, mixed> $theme
 * @param array<string, mixed> $plugins
 * @return array<string, mixed>
 */
function openmira_project_map_build_tools(array $theme, array $plugins): array
{
    $locations = [ABSPATH];
    foreach (['stylesheet_dir', 'template_dir'] as $key) {
        $path = openmira_project_map_absolute_path((string) ($theme[$key] ?? ''));
        if ($path !== '' && is_dir($path)) {
            $locations[] = $path;
        }
    }
    foreach (openmira_project_map_array_list($plugins['items'] ?? []) as $plugin) {
        if (($plugin['active'] ?? false) !== true) {
            continue;
        }
        $path = openmira_project_map_absolute_path((string) ($plugin['dir'] ?? ''));
        if ($path !== '' && is_dir($path)) {
            $locations[] = $path;
        }
    }

    $locations = array_values(array_unique($locations));
    $tools = [];
    foreach ($locations as $location) {
        $tools[] = openmira_project_map_build_tool_location($location);
    }

    return [
        'locations' => array_values(array_filter(
            $tools,
            static fn(array $tool): bool => ($tool['detected'] ?? false) === true,
        )),
    ];
}

/**
 * Return build-tool metadata for one location.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_build_tool_location(string $location): array
{
    $package_json = $location . '/package.json';
    $composer_json = $location . '/composer.json';
    $detected = false;
    $scripts = [];
    $composer = false;
    $wordpress_scripts = false;

    if (is_file($package_json)) {
        $detected = true;
        $package = openmira_project_map_read_json_file($package_json);
        if (is_array($package)) {
            // @mago-expect analysis:mixed-assignment
            $scripts_value = $package['scripts'] ?? [];
            $scripts = is_array($scripts_value) ? array_keys($scripts_value) : [];
            $dependencies = array_merge(
                is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
                is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
            );
            $wordpress_scripts = array_key_exists('@wordpress/scripts', $dependencies);
        }
    }

    if (is_file($composer_json)) {
        $detected = true;
        $composer = true;
    }

    return [
        'detected' => $detected,
        'path' => openmira_project_map_relative_path($location),
        'package_json' => is_file($package_json),
        'composer_json' => $composer,
        'wordpress_scripts' => $wordpress_scripts,
        'npm_scripts' => $scripts,
        'vite' => is_file($location . '/vite.config.js') || is_file($location . '/vite.config.ts'),
        'webpack' => is_file($location . '/webpack.config.js'),
    ];
}

/**
 * Return writable locations for generated code.
 *
 * @param array<string, mixed> $theme
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_writable_locations(array $theme): array
{
    return [
        [
            'role' => 'sandbox',
            'path' => openmira_project_map_relative_path(OPENMIRA_SANDBOX_DIR),
            'writable' => wp_is_writable(OPENMIRA_SANDBOX_DIR) || wp_is_writable(dirname(OPENMIRA_SANDBOX_DIR)),
            'recommended_for' => 'Experimental PHP plugins and snippets before graduation.',
        ],
        [
            'role' => 'active_theme',
            'path' => (string) ($theme['stylesheet_dir'] ?? ''),
            'writable' => wp_is_writable(openmira_project_map_absolute_path((string) ($theme['stylesheet_dir'] ?? ''))),
            'recommended_for' => 'Theme templates, patterns, assets, and theme.json changes.',
        ],
        [
            'role' => 'plugins',
            'path' => openmira_project_map_relative_path(WP_PLUGIN_DIR),
            'writable' => wp_is_writable(WP_PLUGIN_DIR),
            'recommended_for' => 'Graduated plugins after sandbox validation.',
        ],
    ];
}

/**
 * Return project rules from .openmirarules if present.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_rules(): array
{
    if (function_exists('openmira_get_project_rules')) {
        return openmira_get_project_rules();
    }

    $candidates = openmira_project_map_rules_candidates();
    foreach ($candidates as $candidate) {
        $absolute = openmira_project_map_absolute_path((string) ($candidate['path'] ?? ''));
        if ($absolute === '' || !is_readable($absolute)) {
            continue;
        }
        $contents = file_get_contents($absolute);
        if (!is_string($contents)) {
            continue;
        }
        // @mago-expect analysis:mixed-assignment
        $json = json_decode($contents, associative: true);

        return [
            'found' => true,
            'path' => $candidate['path'],
            'format' => is_array($json) ? 'json' : 'text',
            'parsed' => is_array($json) ? $json : null,
            'raw' => is_array($json) ? '' : $contents,
        ];
    }

    return [
        'found' => false,
        'path' => '',
        'format' => '',
        'parsed' => null,
        'raw' => '',
    ];
}

/**
 * Return likely .openmirarules locations.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_rules_candidates(): array
{
    $paths = [
        ABSPATH . '.openmirarules',
        ABSPATH . '.openmirarules.json',
        WP_CONTENT_DIR . '/.openmirarules',
        get_stylesheet_directory() . '/.openmirarules',
    ];

    $items = [];
    foreach (array_values(array_unique($paths)) as $path) {
        $items[] = [
            'path' => openmira_project_map_relative_path($path),
            'exists' => file_exists($path),
            'readable' => is_readable($path),
        ];
    }

    return $items;
}

/**
 * Collect files from directories, excluding heavy/generated paths.
 *
 * @param list<string> $directories
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_collect_files(array $directories, int $limit, string $focus_path): array
{
    $files = [];
    foreach ($directories as $directory) {
        if (!is_dir($directory) || !is_readable($directory)) {
            continue;
        }

        $directory_iterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered_iterator = new RecursiveCallbackFilterIterator(
            $directory_iterator,
            static fn($item): bool => !$item instanceof SplFileInfo
            || !openmira_project_map_should_skip_path($item->getPathname()),
        );
        $iterator = new RecursiveIteratorIterator($filtered_iterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $pathname = $item->getPathname();
            if (openmira_project_map_should_skip_path($pathname)) {
                continue;
            }
            $files[] = openmira_project_map_file_item($pathname, $focus_path);
            if (count($files) >= $limit) {
                break 2;
            }
        }
    }

    return openmira_project_map_sort_files($files, $focus_path);
}

/**
 * Filter file items by relative path regex.
 *
 * @param list<array<array-key, mixed>> $files
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_filter_files(array $files, string $pattern, int $limit, string $focus_path): array
{
    $filtered = [];
    foreach ($files as $file) {
        $path = (string) ($file['path'] ?? '');
        if (preg_match($pattern, $path) !== 1) {
            continue;
        }
        $filtered[] = $file;
    }

    return array_slice(openmira_project_map_sort_files($filtered, $focus_path), offset: 0, length: $limit);
}

/**
 * Return one file item.
 *
 * @return array<string, mixed>
 */
function openmira_project_map_file_item(string $path, string $focus_path): array
{
    $relative = openmira_project_map_relative_path($path);
    $modified = filemtime($path);
    $size = filesize($path);

    return [
        'path' => $relative,
        'basename' => basename($path),
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
        'size' => $size === false ? 0 : $size,
        'modified' => $modified === false ? '' : gmdate('c', $modified),
        'focused' => $focus_path !== '' && $relative === $focus_path,
    ];
}

/**
 * Sort file items with focused files first, then by path.
 *
 * @param list<array<array-key, mixed>> $files
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_sort_files(array $files, string $focus_path): array
{
    usort($files, static function (array $first, array $second) use ($focus_path): int {
        $first_path = (string) ($first['path'] ?? '');
        $second_path = (string) ($second['path'] ?? '');
        if ($focus_path !== '' && $first_path === $focus_path) {
            return -1;
        }
        if ($focus_path !== '' && $second_path === $focus_path) {
            return 1;
        }

        return strcmp($first_path, $second_path);
    });

    return $files;
}

/**
 * Return whether a path should be skipped in project maps.
 */
function openmira_project_map_should_skip_path(string $path): bool
{
    $normalized = str_replace(search: '\\', replace: '/', subject: $path);
    $segments = explode(separator: '/', string: trim($normalized, characters: '/'));
    foreach ([
        '.claude',
        '.codex',
        '.git',
        '.playwright-mcp',
        '.wordpress-playground',
        '#reference',
        '#refenrence',
        '#runtime',
        'cache',
        'node_modules',
        'uploads',
        'vendor',
    ] as $skip) {
        if (in_array($skip, $segments, strict: true)) {
            return true;
        }
    }

    return false;
}

/**
 * Convert absolute path to ABSPATH-relative path when possible.
 */
function openmira_project_map_relative_path(string $path): string
{
    $normalized_path = wp_normalize_path($path);
    $normalized_abspath = wp_normalize_path(ABSPATH);
    if (str_starts_with($normalized_path, $normalized_abspath)) {
        return ltrim(string: substr($normalized_path, strlen($normalized_abspath)), characters: '/');
    }

    return $normalized_path;
}

/**
 * Convert an ABSPATH-relative path back to absolute.
 */
function openmira_project_map_absolute_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return ABSPATH . ltrim(string: $path, characters: '/');
}

/**
 * Normalize focus path to the same relative form used in file items.
 */
function openmira_project_map_normalize_focus_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    return openmira_project_map_relative_path(openmira_project_map_absolute_path($path));
}

/**
 * Read a JSON file.
 *
 * @return array<array-key, mixed>|null
 */
function openmira_project_map_read_json_file(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment
    $decoded = json_decode($contents, associative: true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Return the mu-plugins directory as an absolute path.
 */
function openmira_project_map_mu_plugin_dir(): string
{
    if (!defined('WPMU_PLUGIN_DIR')) {
        return '';
    }
    // @mago-expect analysis:mixed-assignment
    $dir = constant('WPMU_PLUGIN_DIR');

    return is_string($dir) ? $dir : '';
}

/**
 * Normalize a mixed value into a list of arrays.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_project_map_array_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $list = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $list[] = $item;
    }

    return $list;
}
