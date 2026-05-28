<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Plugin Name: Open Mira
 * Plugin URI: https://github.com/worldoptimizer/openmira
 * Description: Open WordPress MCP server with filesystem, PHP execution, builder context, and persistent project memory. For development and staging environments only.
 * Version: 1.7.1
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Open Mira contributors
 * Author URI: https://github.com/worldoptimizer
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: open-mira
 * Copyright: Ovation S.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit();
}

define(constant_name: 'OPENMIRA_VERSION', value: '1.7.1');
define(constant_name: 'OPENMIRA_MAX_EXECUTION_TIME', value: 30);
if (!defined('OPENMIRA_BLOCK_PRODUCTION')) {
    define(constant_name: 'OPENMIRA_BLOCK_PRODUCTION', value: false);
}
define('OPENMIRA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OPENMIRA_SANDBOX_DIR', WP_CONTENT_DIR . '/openmira-sandbox/');
define(constant_name: 'OPENMIRA_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'OPENMIRA_MCP_ADAPTER_CLASS', value: 'WP\\MCP\\Core\\McpAdapter');

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function openmira_load_bundled_dependencies()
{
    if (!file_exists(OPENMIRA_VENDOR_AUTOLOAD)) {
        return new WP_Error('openmira_missing_vendor', __(
            'Open Mira is installed without its bundled vendor directory. This usually means the GitHub/source ZIP was installed instead of a built plugin ZIP. The MCP Adapter cannot load, so Open Mira will not register an MCP endpoint. Run Composer or install a build ZIP before using Open Mira.',
            domain: 'open-mira',
        ));
    }

    try {
        require_once OPENMIRA_VENDOR_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('openmira_autoload_failed', sprintf(
            __(
                'Open Mira could not load its bundled Composer dependencies. The MCP Adapter cannot load, so Open Mira will not register an MCP endpoint. Reinstall a build ZIP or run Composer. Error: %s',
                domain: 'open-mira',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(OPENMIRA_MCP_ADAPTER_CLASS)) {
        return new WP_Error('openmira_mcp_adapter_missing', sprintf(
            __(
                'Open Mira loaded its Composer autoloader, but the MCP Adapter class (%s) is not available. Open Mira will not register an MCP endpoint. Reinstall a build ZIP or run Composer.',
                domain: 'open-mira',
            ),
            OPENMIRA_MCP_ADAPTER_CLASS,
        ));
    }

    return null;
}

/**
 * Store a runtime MCP dependency error.
 */
function openmira_set_mcp_dependency_error(WP_Error $error): void
{
    openmira_mcp_dependency_error($error);
}

/**
 * Return the current MCP dependency error, if any.
 *
 * @return WP_Error|null
 */
function openmira_get_mcp_dependency_error()
{
    return openmira_mcp_dependency_error();
}

/**
 * Shared storage for the current MCP dependency error.
 *
 * @return WP_Error|null
 */
function openmira_mcp_dependency_error(?WP_Error $error = null)
{
    static $current = null;

    if ($error !== null) {
        $current = $error;
    }

    return $current;
}

/**
 * Whether the bundled MCP Adapter is available for Open Mira to initialize.
 */
function openmira_is_mcp_adapter_available(): bool
{
    return openmira_get_mcp_dependency_error() === null && class_exists(OPENMIRA_MCP_ADAPTER_CLASS);
}

/**
 * Block activation when the distributable dependencies are missing.
 */
function openmira_activation_check(): void
{
    $error = openmira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(plugin_basename(__FILE__));
    }

    wp_die(
        '<p>' . esc_html($error->get_error_message()) . '</p>',
        esc_html__('Open Mira installation is incomplete', domain: 'open-mira'),
        ['back_link' => true],
    );
}

/**
 * Show a persistent admin error when Open Mira cannot expose MCP.
 */
function openmira_render_mcp_dependency_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if (is_string($page) && in_array($page, ['openmira-connect', 'openmira', 'openmira-sandbox'], strict: true)) {
        return;
    }

    $error = openmira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type' => 'error',
        'dismissible' => false,
    ]);
}

/**
 * Return a clear REST error at the MCP endpoint when the adapter cannot register its own route.
 */
function openmira_register_missing_mcp_endpoint(): void
{
    $error = openmira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    $routes = rest_get_server()->get_routes();
    $callback = static fn() => new WP_Error('openmira_mcp_adapter_unavailable', $error->get_error_message(), [
        'status' => 500,
    ]);

    foreach (['openmira', 'mcp-adapter-default-server'] as $route_slug) {
        if (array_key_exists('/mcp/' . $route_slug, $routes)) {
            continue;
        }
        register_rest_route('mcp', '/' . $route_slug, [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
}

/**
 * Initialize the MCP Adapter and convert runtime failures into visible admin notices.
 */
function openmira_initialize_mcp_adapter(): bool
{
    if (!openmira_is_mcp_adapter_available()) {
        return false;
    }

    try {
        \WP\MCP\Core\McpAdapter::instance();
        return true;
    } catch (\Throwable $e) {
        openmira_set_mcp_dependency_error(
            new WP_Error('openmira_mcp_adapter_init_failed', sprintf(
                __(
                    'Open Mira found the MCP Adapter, but it failed during initialization. Open Mira will not register an MCP endpoint. Error: %s',
                    domain: 'open-mira',
                ),
                $e->getMessage(),
            )),
        );
        return false;
    }
}

$openmira_dependency_error = openmira_load_bundled_dependencies();
if ($openmira_dependency_error !== null) {
    openmira_set_mcp_dependency_error($openmira_dependency_error);
}

register_activation_hook(__FILE__, callback: 'openmira_activation_check');
add_action('admin_notices', callback: 'openmira_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'openmira_render_mcp_dependency_notice');
add_action('rest_api_init', callback: 'openmira_register_missing_mcp_endpoint', priority: 999);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/skills-loader.php';
require_once __DIR__ . '/includes/skills/cpt.php';
require_once __DIR__ . '/includes/file-safety.php';
require_once __DIR__ . '/includes/diagnostics.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/admin-editor.php';
require_once __DIR__ . '/includes/connect-page.php';
require_once __DIR__ . '/includes/block-tools-page.php';
require_once __DIR__ . '/includes/memory-store.php';
require_once __DIR__ . '/includes/project-rules.php';
require_once __DIR__ . '/includes/memory-page.php';
require_once __DIR__ . '/includes/audit-page.php';
require_once __DIR__ . '/includes/skills-admin-actions.php';
require_once __DIR__ . '/includes/skills-page.php';
require_once __DIR__ . '/includes/upload-link.php';

// Dependency check: Abilities API must be active.
if (!class_exists('WP_Ability')) {
    add_action('admin_notices', static function () {
        wp_admin_notice(
            esc_html__(
                'Open Mira requires the Abilities API plugin to be installed and activated.',
                domain: 'open-mira',
            ),
            [
                'type' => 'error',
                'dismissible' => false,
            ],
        );
    });
    return;
}

// Suppress noisy admin notices on the Configuration page via CSS: hide notices that are not
// emitted by Open Mira. Cheap and side-effect free, unlike iterating $wp_filter
// with Reflection (which causes memory blow-ups when Query Monitor captures every remove_action).
add_action('admin_head', static function () {
    if (($_GET['page'] ?? null) !== 'openmira-connect') {
        return;
    }
    ?>
    <style id="openmira-suppress-foreign-notices">
        .wrap > .notice:not(.openmira-keep),
        #wpbody-content > .notice:not(.openmira-keep),
        #wpbody-content > .updated:not(.openmira-keep),
        #wpbody-content > .error:not(.openmira-keep) {
            display: none !important;
        }
    </style>
    <?php
});

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'openmira-sandbox') {
        openmira_handle_sandbox_actions();
    }
    if ($page === 'openmira-connect') {
        openmira_handle_revoke_password();
        openmira_handle_dismiss_production_warning();
    }
    if ($page === 'openmira-memory') {
        openmira_handle_memory_admin_actions();
    }
    if ($page === 'openmira-skills') {
        openmira_handle_skill_admin_actions();
    }
    if ($page === 'openmira-audit') {
        openmira_handle_audit_admin_actions();
    }
});

// Register admin menus.
add_action('admin_menu', static function () {
    // Top-level menu item (shows the Connect page).
    add_menu_page(
        page_title: __('Configuration', domain: 'open-mira'),
        menu_title: 'Open Mira',
        capability: 'manage_options',
        menu_slug: 'openmira-connect',
        callback: 'openmira_render_connect_page',
        icon_url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xNiAzYTEzIDEzIDAgMSAwIDAgMjYgMTMgMTMgMCAwIDAgMC0yNlptMCA1YTggOCAwIDEgMSAwIDE2IDggOCAwIDAgMSAwLTE2WiIvPjxwYXRoIGZpbGw9ImJsYWNrIiBkPSJNMjUgNGwxLjIgMy44TDMwIDlsLTMuOCAxLjJMMjUgMTRsLTEuMi0zLjhMMjAgOWwzLjgtMS4yTDI1IDRaIi8+PC9zdmc+',
        position: 3,
    );

    // Rename the auto-created first submenu entry to match the page title.
    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('Configuration', domain: 'open-mira'),
        menu_title: __('Configuration', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira-connect',
        callback: 'openmira_render_connect_page',
    );

    // AI Abilities sub-page.
    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('AI Abilities', domain: 'open-mira'),
        menu_title: __('AI Abilities', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira',
        callback: 'openmira_render_settings_page',
    );

    // Sandbox sub-page.
    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('Sandbox', domain: 'open-mira'),
        menu_title: __('Sandbox', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira-sandbox',
        callback: 'openmira_render_sandbox_page',
    );

    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('Block Tools', domain: 'open-mira'),
        menu_title: __('Block Tools', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira-block-tools',
        callback: 'openmira_render_block_tools_page',
    );

    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('Memory', domain: 'open-mira'),
        menu_title: __('Memory', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira-memory',
        callback: 'openmira_render_memory_page',
    );

    add_submenu_page(
        parent_slug: 'openmira-connect',
        page_title: __('Audit Log', domain: 'open-mira'),
        menu_title: __('Audit Log', domain: 'open-mira'),
        capability: 'manage_options',
        menu_slug: 'openmira-audit',
        callback: 'openmira_render_audit_page',
    );
});

$is_enabled = openmira_is_enabled();

if (!$is_enabled && openmira_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        /** @var string $locked */
        $locked = get_option('openmira_ai_abilities_domain', default_value: '');
        wp_admin_notice(
            sprintf(
                esc_html__(
                    'Open Mira AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the Configuration page if this is intentional.',
                    domain: 'open-mira',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            ['type' => 'warning', 'dismissible' => true],
        );
    });
}

if ($is_enabled) {
    add_filter('mcp_adapter_create_default_server', callback: '__return_false');

    // Brand the default MCP server. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (!is_array($config)) {
            return $config;
        }
        $config['server_id'] = 'openmira';
        $config['server_route'] = 'openmira';
        $config['server_name'] = 'Open Mira';
        return $config;
    });

    add_action('mcp_adapter_init', [\WP\MCP\Servers\DefaultServerFactory::class, 'create']);

    // Register a legacy alias server at the old slug so configs that still point at
    // /wp-json/mcp/mcp-adapter-default-server keep working after the rename.
    add_action('mcp_adapter_init', callback: 'openmira_register_legacy_mcp_server', priority: 20);

    // Initialize bundled MCP Adapter — its default server exposes our abilities automatically.
    if (!openmira_initialize_mcp_adapter()) {
        $is_enabled = false;
    }
}

/**
 * Register a legacy alias of the canonical Open Mira MCP server at the pre-rename slug.
 *
 * The canonical server is registered under `/mcp/openmira`. Older client configs may still
 * point at `/mcp/mcp-adapter-default-server` from before the rename — this alias keeps them
 * working with identical behavior (same tools, same auto-discovered resources and prompts).
 */
function openmira_register_legacy_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('openmira') === null) {
        return;
    }

    $resources = openmira_discover_public_abilities('resource');
    if (function_exists('openmira_get_project_map_mcp_resources')) {
        $resources = array_merge($resources, openmira_get_project_map_mcp_resources());
    }
    if (function_exists('openmira_get_memory_mcp_resources')) {
        $resources = array_merge($resources, openmira_get_memory_mcp_resources());
    }

    // The adapter accepts direct McpResource instances at runtime even though its PHPDoc
    // still narrows this argument to ability-name strings.
    // @mago-expect analysis:possibly-invalid-argument
    $adapter->create_server(
        'mcp-adapter-default-server',
        'mcp',
        'mcp-adapter-default-server',
        'Open Mira (legacy alias)',
        'Legacy alias for the Open Mira MCP server. New client configurations should use /wp-json/mcp/openmira.',
        'v1.0.0',
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ],
        $resources,
        openmira_discover_public_abilities('prompt'),
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function openmira_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        $ability_type = (string) ($meta['mcp']['type'] ?? 'tool');
        if ($ability_type !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}

if ($is_enabled) {
    // The `mcp-adapter/execute-ability` dispatcher wraps every ability return in
    // `{ success: true, data: <inner> }`. When the inner value is itself
    // `{ success: false, error: "..." }` the outer `success: true` masks a real
    // logical failure, and agents that check the top-level flag — a very
    // reasonable default — silently march past the error. Unwrap that shape
    // here so the adapter's backward-compat path (ToolsHandler) turns it into a
    // proper `isError: true` CallToolResult.
    //
    // ToolsHandler::create_error_result flattens the response to a bare
    // `content: [text(error)], structuredContent: null, isError: true` — every
    // sibling field on the ability's return is discarded. Validators attach
    // structured repair hints (`invalid_values`, `unknown_properties`,
    // `collision_paths`, `suggested_name`, `failed_paths`, `overwritten_paths`,
    // `errors`, `schemas`, `style_errors`, `dynamic_tag_errors`, `dropped_keys`,
    // `schema`, …) that the agent needs to self-correct without a
    // round-trip — so embed whatever else the ability returned as a JSON
    // suffix on the error message. The suffix rides inside the string and
    // survives the downstream flatten.
    add_filter(
        'mcp_adapter_tool_call_result',
        static function (mixed $result, array $args, string $tool_name): mixed {
            // Tool names are MCP-sanitized from ability slugs — `/` becomes `-`.
            if ($tool_name !== 'mcp-adapter-execute-ability') {
                return $result;
            }
            if (!is_array($result) || ($result['success'] ?? null) !== true) {
                return $result;
            }
            /** @var array<array-key, mixed>|null $data */
            $data = $result['data'] ?? null;
            if (!is_array($data) || ($data['success'] ?? null) !== false) {
                return $result;
            }
            /** @var string|null $error */
            $error = $data['error'] ?? null;
            if (!is_string($error) || trim($error) === '') {
                return $result;
            }

            $detail = $data;
            unset($detail['success'], $detail['error']);
            if ($detail !== []) {
                $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
                }
            }

            return $data;
        },
        priority: 10,
        accepted_args: 3,
    );

    // Fix empty "properties" in JSON Schema: PHP json_encode outputs [] instead of {}.
    // MCP clients reject tools with invalid schemas, so we fix this in the REST response.
    add_filter('rest_pre_echo_response', static function (mixed $result): mixed {
        if (!is_array($result)) {
            return $result;
        }
        /** @var \stdClass|null $resultObj */
        $resultObj = $result['result'] ?? null;
        if (!$resultObj instanceof \stdClass) {
            return $result;
        }
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $resultObj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }
        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                /** @var array<string, mixed>|null $schema */
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }
                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Admin bar indicator when MCP is active.
    add_action(
        'admin_bar_menu',
        static function (\WP_Admin_Bar $wp_admin_bar) {
            if (!current_user_can('manage_options')) {
                return;
            }

            $wp_admin_bar->add_node([
                'id' => 'openmira-mcp-status',
                'title' => esc_html__('Open Mira ON', domain: 'open-mira'),
                'href' => admin_url('admin.php?page=openmira-connect'),
                'meta' => ['class' => 'openmira-mcp-on'],
            ]);
        },
        priority: 999,
    );

    add_action('admin_head', static function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo
            '<style>#wp-admin-bar-openmira-mcp-status > .ab-item { background:#c00 !important; color:#fff !important; }</style>'
        ;
    });

    add_action('wp_head', static function () {
        if (current_user_can('manage_options') && is_admin_bar_showing()) {
            echo
                '<style>#wp-admin-bar-openmira-mcp-status > .ab-item { background:#c00 !important; color:#fff !important; }</style>'
            ;
        }
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', static function () {
            wp_admin_notice(
                esc_html__(
                    'Open Mira bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
                    domain: 'open-mira',
                ),
                [
                    'type' => 'info',
                    'dismissible' => true,
                ],
            );
        });
    }

    // Register ability categories.
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('code-execution', [
            'label' => __('Code Execution', domain: 'open-mira'),
            'description' => __('Abilities that execute code on the WordPress server.', domain: 'open-mira'),
        ]);

        wp_register_ability_category('filesystem', [
            'label' => __('Filesystem', domain: 'open-mira'),
            'description' => __('Server filesystem operations.', domain: 'open-mira'),
        ]);

        wp_register_ability_category('wordpress-builders', [
            'label' => __('WordPress Builders', domain: 'open-mira'),
            'description' => __(
                'Discovery and guidance for Gutenberg, Bricks, and custom field integrations.',
                domain: 'open-mira',
            ),
        ]);

        wp_register_ability_category('wordpress-development', [
            'label' => __('WordPress Development', domain: 'open-mira'),
            'description' => __(
                'IDE-style WordPress project maps, code navigation, and scaffolding.',
                domain: 'open-mira',
            ),
        ]);

        wp_register_ability_category('memory', [
            'label' => __('Memory', domain: 'open-mira'),
            'description' => __('Persistent project facts shared across AI sessions.', domain: 'open-mira'),
        ]);

        wp_register_ability_category('skills', [
            'label' => __('Skills', domain: 'open-mira'),
            'description' => __('MCP Prompts for repeatable Open Mira workflows.', domain: 'open-mira'),
        ]);

        if (!wp_has_ability_category('mcp-adapter')) {
            wp_register_ability_category('mcp-adapter', [
                'label' => __('MCP Adapter', domain: 'open-mira'),
                'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'open-mira'),
            ]);
        }
    });

    add_filter(
        'wp_register_ability_args',
        callback: 'openmira_prepare_ability_schema_for_agents',
        priority: 10,
        accepted_args: 2,
    );

    // Register abilities.
    add_action('wp_abilities_api_init', static function () {
        $dir = __DIR__ . '/includes/abilities/';
        require_once $dir . 'execute-php.php';
        require_once $dir . 'read-file.php';
        require_once $dir . 'write-file.php';
        require_once $dir . 'edit-file.php';
        require_once $dir . 'delete-file.php';
        require_once $dir . 'file-backups.php';
        require_once $dir . 'safety-mode.php';
        require_once $dir . 'create-upload-link.php';
        require_once $dir . 'disable-file.php';
        require_once $dir . 'enable-file.php';
        require_once $dir . 'list-directory.php';
        require_once $dir . 'search-code.php';
        require_once $dir . 'builder-context.php';
        require_once $dir . 'project-map.php';
        require_once $dir . 'project-rules.php';
        require_once $dir . 'navigation.php';
        require_once $dir . 'lint-file.php';
        require_once $dir . 'run-wpcli.php';
        require_once $dir . 'front-page.php';
        require_once $dir . 'apply-patch.php';
        require_once $dir . 'screenshot-url.php';
        require_once $dir . 'skill.php';
        require_once $dir . 'probe-url.php';
        require_once $dir . 'graduate-sandbox-plugin.php';
        require_once $dir . 'scaffold-theme.php';
        require_once $dir . 'theme-actions.php';
        require_once $dir . 'scaffold-block.php';
        require_once $dir . 'builder-inventory.php';
        require_once $dir . 'block-registry.php';
        require_once $dir . 'builder-authoring.php';
        require_once $dir . 'builder-write.php';
        require_once $dir . 'block-editing.php';
        require_once $dir . 'memory.php';
        if (!wp_has_ability('mcp-adapter/get-ability-info')) {
            \WP\MCP\Abilities\GetAbilityInfoAbility::register();
        }
        if (!wp_has_ability('mcp-adapter/execute-ability')) {
            \WP\MCP\Abilities\ExecuteAbilityAbility::register();
        }
        require_once $dir . 'discover-abilities.php';
    });
}

/**
 * Make Open Mira ability schemas more tolerant for agent clients.
 *
 * WordPress still enforces required fields and value types, but Open Mira does not
 * hard-fail on unknown extra properties. The execute wrapper reports dropped keys
 * and close-name suggestions so clients can correct future calls without burning
 * retry turns.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function openmira_prepare_ability_schema_for_agents(array $args, string $ability_name): array
{
    if (!str_starts_with($ability_name, 'openmira/')) {
        return $args;
    }

    $args = openmira_prepare_ability_permission_callback(args: $args, ability_name: $ability_name);

    if (is_array($args['input_schema'] ?? null) && ($args['input_schema']['type'] ?? '') === 'object') {
        $args['input_schema']['additionalProperties'] = true;
    }

    // @mago-expect analysis:mixed-assignment
    $callback = $args['execute_callback'] ?? null;
    if (!is_callable($callback)) {
        return $args;
    }

    $allowed = openmira_ability_schema_property_names($args);

    $args['execute_callback'] = static function (array $input = []) use ($callback, $allowed, $ability_name): mixed {
        $unknown = openmira_unknown_ability_input_properties(input: $input, allowed: $allowed);
        // @mago-expect analysis:mixed-assignment
        $result = call_user_func($callback, $input);
        if ($unknown === [] || !is_array($result)) {
            return $result;
        }

        $result['_openmira_input_notice'] = [
            'ability' => $ability_name,
            'dropped_properties' => $unknown,
            'allowed_properties' => $allowed,
            'suggestions' => openmira_suggest_property_names($unknown, $allowed),
        ];

        return $result;
    };

    return $args;
}

/**
 * Capture the ability name in the permission callback so filters can target abilities.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function openmira_prepare_ability_permission_callback(array $args, string $ability_name): array
{
    if (($args['permission_callback'] ?? null) !== 'openmira_permission_callback') {
        return $args;
    }

    $args['permission_callback'] = static fn(): bool|WP_Error => openmira_permission_callback($ability_name);

    return $args;
}

/**
 * Return declared input property names for an ability schema.
 *
 * @param array<string, mixed> $args
 * @return list<string>
 */
function openmira_ability_schema_property_names(array $args): array
{
    if (!is_array($args['input_schema']['properties'] ?? null)) {
        return [];
    }

    $allowed = [];
    foreach (array_keys($args['input_schema']['properties']) as $property_name) {
        if (!is_string($property_name)) {
            continue;
        }

        $allowed[] = $property_name;
    }

    return $allowed;
}

/**
 * Return unknown input property names supplied by a client.
 *
 * @param array<array-key, mixed> $input
 * @param list<string> $allowed
 * @return list<string>
 */
function openmira_unknown_ability_input_properties(array $input, array $allowed): array
{
    $unknown = [];
    foreach (array_diff(array_keys($input), $allowed) as $property_name) {
        if (is_int($property_name)) {
            continue;
        }

        $unknown[] = $property_name;
    }

    return $unknown;
}

/**
 * Suggest close property names for unknown ability input keys.
 *
 * @param list<string> $unknown
 * @param list<string> $allowed
 * @return array<string, string>
 */
function openmira_suggest_property_names(array $unknown, array $allowed): array
{
    $suggestions = [];
    foreach ($unknown as $property) {
        $best = '';
        $best_distance = 4;
        foreach ($allowed as $candidate) {
            $distance = levenshtein($property, $candidate);
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best = $candidate;
            }
        }
        if ($best !== '') {
            $suggestions[$property] = $best;
        }
    }

    return $suggestions;
}

// Ensure sandbox directory exists.
wp_mkdir_p(OPENMIRA_SANDBOX_DIR);

// Load sandbox plugins.
require_once __DIR__ . '/includes/sandbox-loader.php';
