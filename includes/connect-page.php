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
 * Handle the enable/disable AI Abilities toggle submission.
 * Returns true on save, null when no submission.
 */
function novamira_handle_toggle_enabled(): ?bool
{
    if (($_POST['novamira_submit'] ?? null) === null) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }

    check_admin_referer('novamira_settings');

    $enabled = ($_POST['novamira_ai_abilities_enabled'] ?? null) !== null;
    update_option('novamira_ai_abilities_enabled', $enabled);
    if ($enabled) {
        update_option('novamira_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        return true;
    }
    delete_option('novamira_ai_abilities_domain');
    return true;
}

function novamira_render_enable_toggle(): void
{
    $enabled = novamira_is_enabled(); ?>
    <form method="post" action="" id="novamira-settings-form" style="margin-bottom: 24px;">
        <?php wp_nonce_field('novamira_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('AI Abilities', domain: 'novamira'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="novamira_ai_abilities_enabled" value="1" id="novamira-enable-checkbox" <?php checked(
                            checked: $enabled,
                            current: true,
                        ); ?> />
                        <?php esc_html_e('Enable AI Abilities', domain: 'novamira'); ?>
                    </label>
                    <p class="description"><strong style="color:#d63638;"><?php esc_html_e(
                        'Security note:',
                        domain: 'novamira',
                    ); ?></strong> <?php esc_html_e(
                        'When enabled, AI agents can execute PHP code and perform filesystem operations on this site. For development and staging environments only — always keep backups.',
                        domain: 'novamira',
                    ); ?></p>
                    <p class="description"><?php esc_html_e(
                        'For best results, use with capable, instruction-following AI models. Configure your MCP client to require approval before executing tools, and review each tool call before allowing it to run.',
                        domain: 'novamira',
                    ); ?></p>
                    <p class="description"><?php esc_html_e(
                        'You choose the AI model, you provide the API key, you review the output. We provide the plugin.',
                        domain: 'novamira',
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(text: __('Save Settings', domain: 'novamira'), type: 'primary', name: 'novamira_submit'); ?>
    </form>
    <script>
    document.getElementById('novamira-settings-form').addEventListener('submit', function (e) {
        var cb = document.getElementById('novamira-enable-checkbox');
        if (cb.checked && !cb.defaultChecked) {
            if (!confirm('<?php echo
                esc_js(__(
                    'AI agents will be able to execute PHP code and access the filesystem. For development and staging environments only. Continue?',
                    domain: 'novamira',
                ))
            ; ?>')) {
                e.preventDefault();
            }
        }
    });
    </script>
    <?php
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

    $status = novamira_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
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
    $pw_status = novamira_app_passwords_status();
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
    </p>
    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline" style="margin:12px 0;">
            <p><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
        </div>
    <?php endif; ?>

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
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php esc_html_e('Create New Application Password', domain: 'novamira'); ?>
        </button>
    </form>

    <?php if ($mcp_passwords !== []): ?>
        <?php

        $count = count($mcp_passwords);
        /* translators: %d: number of existing application passwords */
        $show_label = sprintf(
            _n(single: 'Show existing (%d)', plural: 'Show existing (%d)', number: $count, domain: 'novamira'),
            $count,
        );
        $hide_label = __('Hide existing', domain: 'novamira');
        ?>
        <p style="margin:8px 0 12px;">
            <button
                type="button"
                class="button-link"
                id="novamira-passwords-toggle"
                aria-expanded="false"
                aria-controls="novamira-passwords-list"
                data-show-label="<?php echo esc_attr($show_label); ?>"
                data-hide-label="<?php echo esc_attr($hide_label); ?>"
                onclick="novamiraTogglePasswords(this)"
            ><?php echo esc_html($show_label); ?></button>
        </p>
        <table id="novamira-passwords-list" class="wp-list-table widefat fixed striped" style="display:none;" hidden>
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
        <script>
        window.novamiraTogglePasswords = function (btn) {
            var list = document.getElementById('novamira-passwords-list');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                list.style.display = 'none';
                list.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = btn.getAttribute('data-show-label');
            } else {
                list.style.display = '';
                list.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = btn.getAttribute('data-hide-label');
            }
        };
        </script>
    <?php endif; ?>
    <?php if ($mcp_passwords === []): ?>
        <p style="color:#888;"><?php esc_html_e('No Novamira application passwords yet.', domain: 'novamira'); ?></p>
    <?php endif; ?>
    <?php
}

/**
 * Build the npx server config array shared across multiple MCP clients.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @return array{command: string, args: list<string>, env: array<string, string>}
 */
function novamira_build_npx_server(string $rest_url, string $username, string $display_password): array
{
    return [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => [
            'WP_API_URL' => $rest_url,
            'WP_API_USERNAME' => $username,
            'WP_API_PASSWORD' => $display_password,
        ],
    ];
}

/** @param array<string, mixed> $npx_server */
function novamira_build_zed_json(string $mcp_name, array $npx_server, int $opts): string
{
    return (string) json_encode([
        'context_servers' => [
            $mcp_name => array_merge([
                'source' => 'custom',
                'enabled' => true,
            ], $npx_server),
        ],
    ], $opts);
}

function novamira_build_opencode_json(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
    int $opts,
): string {
    return (string) json_encode([
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
    ], $opts);
}

function novamira_build_codex_toml(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

    return implode("\n", [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
        '',
        '[mcp_servers.' . $mcp_name . '.env]',
        'WP_API_URL = ' . $esc($rest_url),
        'WP_API_USERNAME = ' . $esc($username),
        'WP_API_PASSWORD = ' . $esc($display_password),
    ]);
}

function novamira_build_claude_code_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    return implode(" \\\n  ", [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($display_password),
        '-- npx -y @automattic/mcp-wordpress-remote@latest',
    ]);
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
    $npx_server = novamira_build_npx_server($rest_url, $username, $display_password);
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'novamira');

    $special = [
        'claude-code' => [
            'code' => novamira_build_claude_code_cmd($mcp_name, $rest_url, $username, $display_password),
            'hint' => __('Run in your terminal.', domain: 'novamira'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => [
            'code' => novamira_build_codex_toml($mcp_name, $rest_url, $username, $display_password),
            'hint' => sprintf($add_to, '<code>config.toml</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => false,
        ],
        'zed' => [
            'code' => novamira_build_zed_json($mcp_name, $npx_server, $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => ['macOS / Linux' => '~/.config/zed/settings.json'],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => novamira_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => 'opencode.json',
                __('Global', domain: 'novamira') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
    ];

    return array_merge(novamira_build_standard_configs($mcp_servers_json, $vscode_servers_json), $special);
}

/**
 * Build per-client config entries that reuse the standard mcpServers/servers JSON payloads.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function novamira_build_standard_configs(string $mcp_servers_json, string $vscode_servers_json): array
{
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
            'code' => $vscode_servers_json,
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
        'cline' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>cline_mcp_settings.json</code>'),
            'paths' => [
                __('Via UI', domain: 'novamira') => __(
                    'Cline sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.roo/mcp.json',
                __('Via UI', domain: 'novamira') => __(
                    'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.kilocode/mcp.json',
                __('Via UI', domain: 'novamira') => __(
                    'Kilo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'github-copilot' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.github/copilot/mcp.json',
            ],
            'isShell' => false,
        ],
        'amazon-q' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.aws/amazonq/mcp.json',
                __('Project', domain: 'novamira') => '.amazonq/mcp.json',
            ],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.gemini/settings.json',
                __('Project', domain: 'novamira') => '.gemini/settings.json',
            ],
            'isShell' => false,
        ],
        'antigravity' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.gemini/antigravity/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json',
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
 */
function novamira_render_config_section(string $rest_url, string $username, string $display_password): void
{
    /** @var string $site_host */
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress';
    $site_slug = (string) preg_replace(pattern: '/^www\./', replacement: '', subject: $site_host);
    $site_slug = (string) preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: strtolower($site_slug));
    $default_name = 'novamira-' . trim($site_slug, characters: '-');
    $name_placeholder = '__NOVAMIRA_MCP_NAME__';
    $configs = novamira_build_configs($rest_url, $username, $display_password, $name_placeholder);
    $configs_json = (string) wp_json_encode($configs);

    $clients = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'codex' => 'Codex',
        'antigravity' => 'Antigravity',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'gemini-cli' => 'Gemini CLI',
        'roo-code' => 'Roo Code',
        'amazon-q' => 'Amazon Q',
        'zed' => 'Zed',
        'kilo-code' => 'Kilo Code',
        'opencode' => 'OpenCode',
    ];

    $copied_label = esc_js(__('Copied!', domain: 'novamira'));
    ?>
    <h2><?php

    /* translators: step number and section title */
    esc_html_e('2. Connect Your AI Client', domain: 'novamira');
    ?></h2>
    <p>
        <?php esc_html_e('Select your AI client to get the connection instructions.', domain: 'novamira'); ?>
        <?php printf(
            /* translators: %s: link to Node.js website */
            esc_html__('Requires %s (provides npm/npx).', domain: 'novamira'),
            '<a href="https://nodejs.org/" target="_blank" rel="noopener noreferrer">Node.js</a>',
        ); ?>
    </p>

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
    <p id="novamira-name-warning" style="display:none; margin:0 0 12px; color:#b26200; font-size:13px;">
        <?php esc_html_e(
            'Long server names can cause some AI clients to reject tool calls. Keep the name under 25 characters for best compatibility.',
            domain: 'novamira',
        ); ?>
    </p>

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
        var mcpName = '<?php echo esc_js($default_name); ?>';
        var namePlaceholder = '<?php echo esc_js($name_placeholder); ?>';

        function render() {
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('novamira-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
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
            updateNameWarning(document.getElementById('novamira-mcp-name').value);
        };

        function updateNameWarning(value) {
            var warning = document.getElementById('novamira-name-warning');
            warning.style.display = value.trim().length > 25 ? 'block' : 'none';
        }

        window.novamiraUpdateName = function (value) {
            mcpName = value.trim() || '<?php echo esc_js($default_name); ?>';
            updateNameWarning(value);
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

    $toggle_saved = novamira_handle_toggle_enabled();
    $enabled = novamira_is_enabled();

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

    <?php novamira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('Configuration', domain: 'novamira'); ?></h1>

        <?php if ($toggle_saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php

            esc_html_e('Settings saved.', domain: 'novamira');
            ?></p></div>
        <?php endif; ?>

        <?php novamira_render_enable_toggle(); ?>

        <?php if (!$enabled): ?>
            <p style="color:#666; font-size:14px;">
                <?php esc_html_e(
                    'Enable AI Abilities above to create application passwords and connect an MCP client.',
                    domain: 'novamira',
                ); ?>
            </p>
        <?php endif; ?>
        <?php if ($enabled): ?>
            <?php if ($create_error !== null): ?>
                <div class="notice notice-error"><p><?php

                echo esc_html($create_error->get_error_message());
                ?></p></div>
            <?php endif; ?>

            <?php if ($new_password !== null): ?>
                <div class="notice notice-error" style="border-left-color:#d63638;">
                    <p style="font-size:14px;">
                        <strong><?php esc_html_e(
                            'Application password created — copy it now.',
                            domain: 'novamira',
                        ); ?></strong>
                        <?php esc_html_e(
                            'It will not be shown again after you leave this page.',
                            domain: 'novamira',
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($result_message !== null): ?>
                <div class="notice notice-success is-dismissible"><p><?php

                echo esc_html($result_message);
                ?></p></div>
            <?php endif; ?>

            <div class="novamira-connect-section">
                <?php novamira_render_passwords_section($new_password); ?>
            </div>

            <?php if ($new_password !== null || novamira_get_mcp_passwords() !== []): ?>
            <div class="novamira-connect-section">
                <?php if ($new_password === null): ?>
                    <div class="notice notice-warning inline" style="margin:12px 0;">
                        <p>
                            <?php esc_html_e(
                                'Replace YOUR-APP-PASSWORD in the config below with an application password from step 1.',
                                domain: 'novamira',
                            ); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php novamira_render_config_section($rest_url, $username, $display_password); ?>
            </div>
            <?php endif; ?>
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
