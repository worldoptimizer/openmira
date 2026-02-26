<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Dashboard connect page — creates application passwords and shows MCP config samples.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function novamira_handle_create_password()
{
    if (($_POST['novamira_create_password'] ?? null) === null) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'novamira',
        ));
    }

    check_admin_referer('novamira_create_password');

    if (!wp_is_application_passwords_available()) {
        return new WP_Error('not_available', __(
            'Application Passwords require HTTPS or WP_ENVIRONMENT_TYPE set to "local".',
            domain: 'novamira',
        ));
    }

    $user_id = get_current_user_id();
    $raw_name = $_POST['novamira_password_name'] ?? '';
    $input_name = is_string($raw_name) ? trim($raw_name) : '';
    $app_name = $input_name !== '' ? 'Novamira — ' . $input_name : 'Novamira';

    // Avoid duplicate names — append a counter if one already exists.
    $existing = WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array(needle: $app_name, haystack: $names, strict: true)) {
        $i = 2;
        while (in_array(needle: $app_name . ' ' . $i, haystack: $names, strict: true)) {
            $i++;
        }
        $app_name = $app_name . ' ' . $i;
    }

    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $app_name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result[0] is the plaintext password.
    return $result[0];
}

/**
 * Handle the revoke-password form submission. Redirects on success.
 * Called from admin_init so headers have not been sent yet.
 */
function novamira_handle_revoke_password(): void
{
    if (($_POST['novamira_revoke_password'] ?? null) === null) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $uuid = $_POST['novamira_revoke_uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
        return;
    }

    check_admin_referer('novamira_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=novamira-connect&novamira_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "Novamira".
 *
 * @return array<int, array<string, mixed>>
 */
function novamira_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'Novamira')));
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function novamira_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'novamira');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'novamira');
    $revoke_nonce = (string) wp_create_nonce('novamira_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'novamira'))
            ; ?>');">
                <input type="hidden" name="novamira_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="novamira_revoke_password" class="button button-small novamira-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'novamira',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

function novamira_render_passwords_section(?string $new_password): void
{
    $mcp_passwords = novamira_get_mcp_passwords();
    $dt_format = novamira_get_datetime_format('Y-m-d H:i');
    ?>
    <h2><?php

    /* translators: step number and section title */
    esc_html_e('1. Application Passwords', domain: 'novamira');
    ?></h2>
    <p>
        <?php esc_html_e(
            'Application passwords let AI clients authenticate with WordPress over HTTP without using your main password.',
            domain: 'novamira',
        ); ?>
        <?php if (!wp_is_application_passwords_available()): ?>
            <strong style="color:#d63638;"><?php

            printf(
                /* translators: %s: WP_ENVIRONMENT_TYPE=local in code format */
                esc_html__('Application Passwords require HTTPS or %s.', domain: 'novamira'),
                '<code>WP_ENVIRONMENT_TYPE=local</code>',
            );
            ?></strong>
        <?php endif; ?>
    </p>

    <?php if ($new_password !== null): ?>
        <div class="novamira-password-box">
            <span class="novamira-password-value" id="novamira-pw-value"><?php echo esc_html($new_password); ?></span>
            <button type="button" class="button" onclick="novamiraCopy('novamira-pw-value', this)"><?php esc_html_e(
                'Copy',
                domain: 'novamira',
            ); ?></button>
            <span style="color:#d63638; font-size:14px; font-weight:600;"><?php esc_html_e(
                'Save this somewhere safe — it will not be shown again.',
                domain: 'novamira',
            ); ?></span>
        </div>
    <?php endif; ?>

    <form method="post" style="margin-bottom: 16px; display:flex; align-items:center; gap:8px;">
        <?php wp_nonce_field('novamira_create_password'); ?>
        <input
            type="text"
            name="novamira_password_name"
            placeholder="<?php esc_attr_e('Name (optional, defaults to Novamira)', domain: 'novamira'); ?>"
            style="width:300px;"
            class="regular-text"
            maxlength="70"
        />
        <button
            type="submit"
            name="novamira_create_password"
            class="button button-primary"
            <?php echo !wp_is_application_passwords_available() ? 'disabled' : ''; ?>>
            <?php esc_html_e('Create New Application Password', domain: 'novamira'); ?>
        </button>
    </form>

    <?php if ($mcp_passwords !== []): ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', domain: 'novamira'); ?></th>
                    <th style="width:180px;"><?php esc_html_e('Created', domain: 'novamira'); ?></th>
                    <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'novamira'); ?></th>
                    <th style="width:80px;"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mcp_passwords as $pw): ?>
                    <?php novamira_render_password_row($pw, $dt_format); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($mcp_passwords === []): ?>
        <p style="color:#888;"><?php esc_html_e('No Novamira application passwords yet.', domain: 'novamira'); ?></p>
    <?php endif; ?>
    <?php
}

/**
 * Build all per-client, per-transport config entries.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @param string $mcp_name        MCP server name used as the config key.
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function novamira_build_configs(string $rest_url, string $username, string $display_password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    $npx_server = [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => [
            'WP_API_URL' => $rest_url,
            'WP_API_USERNAME' => $username,
            'WP_API_PASSWORD' => $display_password,
        ],
    ];

    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);

    $claude_code_cmd = implode(" \\\n  ", [
        'claude mcp add ' . $mcp_name,
        '--env WP_API_URL=' . $rest_url,
        '--env WP_API_USERNAME=' . $username,
        '--env WP_API_PASSWORD=' . $display_password,
        '-- npx -y @automattic/mcp-wordpress-remote@latest',
    ]);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'novamira');

    return [
        'claude-desktop' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>claude_desktop_config.json</code>'),
            'paths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
            'isShell' => false,
        ],
        'cursor' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.cursor/mcp.json',
                __('Project', domain: 'novamira') => '.cursor/mcp.json',
            ],
            'isShell' => false,
        ],
        'vscode' => [
            'code' => (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts),
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Workspace', domain: 'novamira') => '.vscode/mcp.json',
                __('User', domain: 'novamira') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'windsurf' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
            ],
            'isShell' => false,
        ],
        'claude-code' => [
            'code' => $claude_code_cmd,
            'hint' => __('Run in your terminal.', domain: 'novamira'),
            'paths' => [],
            'isShell' => true,
        ],
        'zed' => [
            'code' => (string) json_encode(['context_servers' => [$mcp_name => array_merge([
                'source' => 'custom',
                'enabled' => true,
            ], $npx_server)]], $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.config/zed/settings.json',
            ],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => (string) json_encode([
                'mcp' => [
                    $mcp_name => [
                        'type' => 'local',
                        'command' => ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
                        'environment' => [
                            'WP_API_URL' => $rest_url,
                            'WP_API_USERNAME' => $username,
                            'WP_API_PASSWORD' => $display_password,
                        ],
                    ],
                ],
            ], $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => 'opencode.json',
                __('Global', domain: 'novamira') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
    ];
}

/**
 * Render the tabbed MCP client config section.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @param bool   $is_placeholder   Whether the password is a placeholder.
 */
function novamira_render_config_section(
    string $rest_url,
    string $username,
    string $display_password,
    bool $is_placeholder,
): void {
    $default_name = 'wordpress';
    $name_placeholder = '__NOVAMIRA_MCP_NAME__';
    $configs = novamira_build_configs($rest_url, $username, $display_password, $name_placeholder);
    $configs_json = (string) wp_json_encode($configs);

    $clients = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'windsurf' => 'Windsurf',
        'zed' => 'Zed',
        'opencode' => 'OpenCode',
    ];

    $copied_label = esc_js(__('Copied!', domain: 'novamira'));
    ?>
    <h2><?php

    /* translators: step number and section title */
    esc_html_e('2. Add to Your MCP Client Config', domain: 'novamira');
    ?></h2>
    <p><?php esc_html_e('Select your AI client to get the right config snippet.', domain: 'novamira'); ?></p>

    <p id="novamira-name-toggle" style="margin:12px 0;">
        <button type="button" class="button-link" onclick="novamiraShowNameInput()">
            <?php esc_html_e('Customize server name', domain: 'novamira'); ?>
        </button>
    </p>
    <p id="novamira-name-field" style="display:none; align-items:center; gap:8px; margin:12px 0;">
        <label for="novamira-mcp-name"><strong><?php esc_html_e('Server name', domain: 'novamira'); ?></strong></label>
        <input
            type="text"
            id="novamira-mcp-name"
            value="<?php echo esc_attr($default_name); ?>"
            style="width:200px;"
            oninput="novamiraUpdateName(this.value)"
        >
    </p>

    <?php if ($is_placeholder): ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p>
                <?php esc_html_e(
                    'Replace YOUR-APP-PASSWORD in the config below with an application password from step 1.',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="novamira-client-tabs">
        <?php foreach ($clients as $key => $label): ?>
            <button
                type="button"
                class="novamira-client-tab<?php echo $key === 'claude-code' ? ' active' : ''; ?>"
                onclick="novamiraSetClient('<?php echo esc_js($key); ?>', this)"
            ><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>

    <div class="novamira-tab-content" style="border-radius:4px;">
        <div class="novamira-config-block">
            <pre id="novamira-config-code"></pre>
            <button type="button" class="button novamira-copy-btn" onclick="novamiraCopyConfig(this)"><?php esc_html_e(
                'Copy',
                domain: 'novamira',
            ); ?></button>
        </div>
        <div id="novamira-config-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
            <div id="novamira-config-hint" style="padding: 10px 16px;"></div>
            <div id="novamira-config-paths" style="padding: 0 16px 10px;"></div>
        </div>
    </div>

    <script>
    (function () {
        var configs = <?php echo $configs_json; ?>;
        var client = 'claude-code';
        var isPlaceholder = <?php echo $is_placeholder ? 'true' : 'false'; ?>;
        var mcpName = '<?php echo esc_js($default_name); ?>';
        var namePlaceholder = '<?php echo esc_js($name_placeholder); ?>';

        function render() {
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('novamira-config-code');
            codeEl.textContent = code;
            if (isPlaceholder) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="novamira-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('novamira-config-hint').innerHTML = cfg.hint;

            var pathsEl = document.getElementById('novamira-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length > 0) {
                var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
                keys.forEach(function (label) {
                    html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
                });
                html += '</ul>';
                pathsEl.innerHTML = html;
                pathsEl.style.display = '';
            } else {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
            }
        }

        window.novamiraSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.novamira-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            render();
        };

        window.novamiraShowNameInput = function () {
            document.getElementById('novamira-name-toggle').style.display = 'none';
            document.getElementById('novamira-name-field').style.display = 'flex';
            document.getElementById('novamira-mcp-name').focus();
        };

        window.novamiraUpdateName = function (value) {
            mcpName = value.trim() || '<?php echo esc_js($default_name); ?>';
            render();
        };

        window.novamiraCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('novamira-config-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        render();
    }());
    </script>
    <?php
}

/**
 * Render the connect / setup dashboard page.
 */
function novamira_render_connect_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $password_result = novamira_handle_create_password();
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;

    $result_message = match ($_GET['novamira_result'] ?? null) {
        'revoked' => __('Application password revoked.', domain: 'novamira'),
        default => null,
    };

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $rest_url = rest_url('mcp/mcp-adapter-default-server');
    $display_password = $new_password ?? 'YOUR-APP-PASSWORD';

    $copied_label = esc_js(__('Copied!', domain: 'novamira'));

    ?>
    <style>
        .novamira-connect-section { margin: 24px 0; }
        .novamira-connect-section h2 { margin-bottom: 8px; }
        .novamira-config-block { position: relative; }
        .novamira-config-block pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 16px;
            border-radius: 0 4px 0 0;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
        }
        .novamira-copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .novamira-password-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff8e1;
            border: 1px solid #f0c040;
            border-radius: 4px;
            padding: 12px 16px;
            margin: 12px 0;
        }
        .novamira-password-value {
            font-family: monospace;
            font-size: 18px;
            letter-spacing: 1px;
            font-weight: bold;
        }
        .novamira-client-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .novamira-client-tab {
            padding: 5px 14px;
            border: 1px solid #c3c4c7;
            background: #f6f7f7;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
        }
        .novamira-client-tab.active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
            font-weight: 600;
        }
        .novamira-tab-content { border: 1px solid #c3c4c7; border-radius: 4px; }
        .novamira-revoke-btn { color: #d63638 !important; border-color: #d63638 !important; }
        .novamira-placeholder { background: #d63638; color: #fff; padding: 1px 4px; border-radius: 3px; }
    </style>

    <div class="wrap">
        <?php novamira_render_admin_header(); ?>
        <h1><?php esc_html_e('Configuration', domain: 'novamira'); ?></h1>

        <?php if (!novamira_is_enabled()): ?>
            <div class="notice notice-warning" style="border-left-color:#dba617;">
                <p style="font-size:14px;">
                    <strong><?php esc_html_e('AI Abilities are not enabled.', domain: 'novamira'); ?></strong>
                    <?php esc_html_e(
                        'The MCP server is not active. MCP clients will not be able to connect.',
                        domain: 'novamira',
                    ); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=novamira')); ?>"><?php esc_html_e(
                        'Enable AI Abilities in Settings',
                        domain: 'novamira',
                    ); ?></a>.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($create_error !== null): ?>
            <div class="notice notice-error"><p><?php echo esc_html($create_error->get_error_message()); ?></p></div>
        <?php endif; ?>

        <?php if ($new_password !== null): ?>
            <div class="notice notice-error" style="border-left-color:#d63638;">
                <p style="font-size:14px;">
                    <strong><?php esc_html_e('Application password created — copy it now.', domain: 'novamira'); ?></strong>
                    <?php esc_html_e('It will not be shown again after you leave this page.', domain: 'novamira'); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($result_message !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>

        <div class="novamira-connect-section">
            <?php novamira_render_passwords_section($new_password); ?>
        </div>

        <?php if ($new_password !== null || novamira_get_mcp_passwords() !== []): ?>
        <div class="novamira-connect-section">
            <?php novamira_render_config_section($rest_url, $username, $display_password, $new_password === null); ?>
        </div>
        <?php endif; ?>

    </div>

    <script>
    function novamiraCopy(id, btn) {
        var text = document.getElementById(id).textContent;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '<?php echo $copied_label; ?>';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    }
    </script>
    <?php
}
