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
function openmira_handle_toggle_enabled(): ?bool
{
    if (($_POST['openmira_submit'] ?? null) === null) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }

    check_admin_referer('openmira_settings');

    $enabled = ($_POST['openmira_ai_abilities_enabled'] ?? null) !== null;
    if (
        $enabled
        && function_exists('openmira_get_mcp_dependency_error')
        && openmira_get_mcp_dependency_error() !== null
    ) {
        return false;
    }

    update_option('openmira_ai_abilities_enabled', $enabled);
    if ($enabled) {
        update_option('openmira_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        return true;
    }
    delete_option('openmira_ai_abilities_domain');
    return true;
}

function openmira_render_enable_toggle(): void
{
    $enabled = openmira_is_enabled();
    $dependency_error = function_exists('openmira_get_mcp_dependency_error')
        ? openmira_get_mcp_dependency_error()
        : null;
    $toggle_disabled = $dependency_error !== null && !$enabled;
    $submit_attributes = $toggle_disabled ? ['disabled' => 'disabled'] : [];
    $looks_production = openmira_looks_like_production();
    ?>
    <h2 class="openmira-step-heading">
        <span class="openmira-step-badge">1</span>
        <?php esc_html_e('Enable AI Abilities', domain: 'open-mira'); ?>
    </h2>
    <form method="post" action="" id="openmira-settings-form" style="margin: 16px 0 0;">
        <?php wp_nonce_field('openmira_settings'); ?>
        <label style="display:flex; align-items:center; gap:10px; font-size:16px; font-weight:600; color:#1d2327; margin:0 0 12px;">
            <input type="checkbox" name="openmira_ai_abilities_enabled" value="1" id="openmira-enable-checkbox" style="width:18px; height:18px;" <?php checked(
                checked: $enabled,
                current: true,
            ); ?> <?php disabled($toggle_disabled); ?> />
            <span><?php esc_html_e('Turn on AI Abilities for this site', domain: 'open-mira'); ?></span>
        </label>
        <p class="description" style="margin:0 0 8px;">
            <strong style="color:#d63638;"><?php esc_html_e('Security note:', domain: 'open-mira'); ?></strong>
            <?php esc_html_e(
                'When enabled, AI agents can execute PHP code and perform filesystem operations on this site. Open Mira adds backups, audit diffs, capability filters, and production guardrails, but it is still intended for development and staging copies only.',
                domain: 'open-mira',
            ); ?>
        </p>
        <p class="description" style="margin:0 0 14px;">
            <?php esc_html_e(
                'Use a capable AI model, keep your client in confirmation mode for writes, and review what the agent is about to do before approving.',
                domain: 'open-mira',
            ); ?>
        </p>
        <?php submit_button(
            text: __('Save Settings', domain: 'open-mira'),
            type: 'primary',
            name: 'openmira_submit',
            wrap: false,
            other_attributes: $submit_attributes,
        ); ?>
    </form>
    <script>
    document.getElementById('openmira-settings-form').addEventListener('submit', function (e) {
        var cb = document.getElementById('openmira-enable-checkbox');
        if (cb.checked && !cb.defaultChecked) {
            var msg = <?php echo
                wp_json_encode(
                    $looks_production
                        ? __(
                            'This looks like a production site. The plugin can stay installed here, but AI Abilities are not meant for live sites: enable them only on a staging or development copy. Continue anyway?',
                            domain: 'open-mira',
                        )
                        : __(
                            'AI agents will be able to execute PHP code and access the filesystem. For development and staging environments only. Continue?',
                            domain: 'open-mira',
                        ),
                )
            ; ?>;
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });
    </script>
    <?php
}

/**
 * Render the production-site warning banner above the enable toggle.
 *
 * Shown only when: AI Abilities are currently enabled AND the site looks like production
 * AND the current user has not dismissed the warning.
 */
function openmira_render_production_warning(): void
{
    if (!openmira_is_enabled()) {
        return;
    }
    if (!openmira_looks_like_production()) {
        return;
    }
    if (openmira_production_warning_dismissed()) {
        return;
    }
    ?>
    <div class="openmira-production-warning" role="alert">
        <p>
            <strong><?php esc_html_e('⚠️ This looks like a production site.', domain: 'open-mira'); ?></strong>
            <?php esc_html_e(
                'Keeping the plugin installed here is fine, but AI Abilities should only be active on a staging or development copy. For a hard stop on production-looking sites, define OPENMIRA_BLOCK_PRODUCTION in wp-config.php.',
                domain: 'open-mira',
            ); ?>
        </p>
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('openmira_dismiss_production_warning'); ?>
            <button type="submit" name="openmira_dismiss_production_warning" class="button button-small">
                <?php esc_html_e('Dismiss', domain: 'open-mira'); ?>
            </button>
        </form>
    </div>
    <?php
}

/**
 * Compute the default MCP server name from the current site host.
 *
 * Capped at 25 characters total ("openmira-" prefix + up to 16 chars of host slug)
 * because some MCP clients reject longer server names. Used as the placeholder default
 * when no name has been saved by the user.
 */
function openmira_get_mcp_server_name_default(): string
{
    /** @var string $site_host */
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress';
    $site_slug = (string) preg_replace(pattern: '/^www\./', replacement: '', subject: $site_host);
    $site_slug = (string) preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: strtolower($site_slug));
    $site_slug = trim($site_slug, characters: '-');
    $site_slug = substr($site_slug, offset: 0, length: 16);
    $site_slug = rtrim($site_slug, characters: '-');
    return 'openmira-' . $site_slug;
}

/**
 * Handle the "use existing password" form submission.
 *
 * Returns the pasted plaintext value (only for the current request — never persisted),
 * a WP_Error on validation failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function openmira_handle_use_existing_password()
{
    if (($_POST['openmira_use_existing_password'] ?? null) === null) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', __(
            'You do not have permission to use application passwords.',
            domain: 'open-mira',
        ));
    }

    check_admin_referer('openmira_use_existing_password');

    $raw = $_POST['openmira_existing_password'] ?? '';
    $value = is_string($raw) ? trim($raw) : '';
    if ($value === '') {
        return new WP_Error('empty', __(
            'Paste the application password value before submitting.',
            domain: 'open-mira',
        ));
    }
    if (strlen($value) < 16) {
        return new WP_Error('too_short', __(
            'That does not look like an application password. WordPress application passwords are at least 16 characters long.',
            domain: 'open-mira',
        ));
    }
    return $value;
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function openmira_handle_create_password()
{
    if (($_POST['openmira_create_password'] ?? null) === null) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'open-mira',
        ));
    }

    check_admin_referer('openmira_create_password');

    $status = openmira_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
    }

    $user_id = get_current_user_id();
    $raw_name = $_POST['openmira_password_name'] ?? '';
    $input_name = is_string($raw_name) ? trim($raw_name) : '';
    $app_name = $input_name !== '' ? 'Open Mira: ' . $input_name : 'Open Mira';

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
function openmira_handle_revoke_password(): void
{
    if (($_POST['openmira_revoke_password'] ?? null) === null) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $uuid = $_POST['openmira_revoke_uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
        return;
    }

    check_admin_referer('openmira_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=openmira-connect&openmira_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "Open Mira".
 *
 * @return array<int, array<string, mixed>>
 */
function openmira_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'Open Mira')));
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function openmira_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'open-mira');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'open-mira');
    $revoke_nonce = (string) wp_create_nonce('openmira_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'open-mira'))
            ; ?>');">
                <input type="hidden" name="openmira_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="openmira_revoke_password" class="button button-small openmira-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'open-mira',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

/**
 * Render the "Step 2 — Application Password" card.
 *
 * Just the generate button (with a collapsible name input) and a success notice after generation.
 * The list of existing passwords lives in the separate manage section at the bottom of the page.
 */
function openmira_render_password_step(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
): void {
    $pw_status = openmira_app_passwords_status();
    $has_existing = openmira_get_mcp_passwords() !== [];
    $existing_section_open = $existing_password !== null || $existing_error !== null;
    ?>
    <h2 class="openmira-step-heading">
        <span class="openmira-step-badge">2</span>
        <?php esc_html_e('Application Password', domain: 'open-mira'); ?>
    </h2>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Generate an application password that your AI client will use to authenticate with WordPress. The password is embedded into the connection text in step 3.',
            domain: 'open-mira',
        ); ?>
    </p>

    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline" style="margin:12px 0 16px;">
            <p><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
            <?php if ($pw_status['reason'] === 'unsupported' && openmira_likely_local_http()): ?>
                <p style="margin:8px 0 0;">
                    <?php esc_html_e(
                        'This site is on a local hostname over HTTP. Add this line to your wp-config.php (above the "/* That\'s all" comment), then reload:',
                        domain: 'open-mira',
                    ); ?>
                </p>
                <pre style="background:#f6f7f7; border:1px solid #c3c4c7; padding:8px 12px; margin:6px 0 0; font-size:13px; border-radius:3px;">define('WP_ENVIRONMENT_TYPE', 'local');</pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($new_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><?php esc_html_e(
                'Application password generated. It is now embedded in the connection text in step 3. Save it somewhere safe: it will not be shown in full again.',
                domain: 'open-mira',
            ); ?></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="openmira-new-pw-value" style="font-size:14px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:3px;"><?php echo
                    esc_html($new_password)
                ; ?></code>
                <button type="button" class="button button-small" onclick="openmiraCopy('openmira-new-pw-value', this)">
                    <?php esc_html_e('Copy password only', domain: 'open-mira'); ?>
                </button>
            </div>
        </div>
    <?php elseif ($existing_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php esc_html_e(
                'Password accepted. It is now embedded in the connection text in step 3.',
                domain: 'open-mira',
            ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 0;">
        <?php wp_nonce_field('openmira_create_password'); ?>
        <?php if (!$has_existing): ?>
            <p style="margin:0 0 10px;">
                <button
                    type="button"
                    class="button-link"
                    id="openmira-password-name-toggle"
                    aria-expanded="false"
                    aria-controls="openmira-password-name-field"
                    onclick="openmiraTogglePasswordName(this)"
                ><?php esc_html_e('Customize password name (optional)', domain: 'open-mira'); ?></button>
            </p>
        <?php endif; ?>
        <div
            id="openmira-password-name-field"
            <?php echo $has_existing ? '' : 'hidden'; ?>
            style="margin: 0 0 12px; <?php echo $has_existing ? '' : 'display:none;'; ?>"
        >
            <label for="openmira-password-name" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Name', domain: 'open-mira'); ?></strong>
            </label>
            <input
                type="text"
                id="openmira-password-name"
                name="openmira_password_name"
                placeholder="<?php esc_attr_e('e.g. Cursor on laptop, Claude Desktop', domain: 'open-mira'); ?>"
                style="width:300px;"
                class="regular-text"
                maxlength="70"
            />
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'A label to identify this credential later. Leave blank to use "Open Mira".',
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>
        <button
            type="submit"
            name="openmira_create_password"
            class="button button-primary"
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php echo
                $has_existing
                    ? esc_html__('Generate another application password', domain: 'open-mira')
                    : esc_html__('Generate application password', domain: 'open-mira')
            ; ?>
        </button>
    </form>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="openmira-use-existing-toggle"
            aria-expanded="<?php echo $existing_section_open ? 'true' : 'false'; ?>"
            aria-controls="openmira-use-existing-field"
            onclick="openmiraToggleUseExisting(this)"
        ><?php esc_html_e('I already have an application password', domain: 'open-mira'); ?></button>
    </p>
    <div
        id="openmira-use-existing-field"
        <?php echo $existing_section_open ? '' : 'hidden'; ?>
        style="margin:6px 0 0; <?php echo $existing_section_open ? '' : 'display:none;'; ?>"
    >
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('openmira_use_existing_password'); ?>
            <label for="openmira-existing-password" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Paste the password value', domain: 'open-mira'); ?></strong>
            </label>
            <input
                type="text"
                id="openmira-existing-password"
                name="openmira_existing_password"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                style="width:340px; font-family:monospace;"
                class="regular-text"
                autocomplete="off"
            />
            <button type="submit" name="openmira_use_existing_password" class="button">
                <?php esc_html_e('Use this password', domain: 'open-mira'); ?>
            </button>
            <?php if ($existing_error !== null): ?>
                <div class="notice notice-error inline" style="margin:8px 0 0;">
                    <p style="margin:0;"><?php echo esc_html($existing_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'For reusing an application password you already saved (e.g. from a password manager). It is used only to fill the connection text and never stored on this site.',
                    domain: 'open-mira',
                ); ?>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the "Manage existing application passwords" collapsible section at the bottom of the page.
 *
 * Only meaningful when at least one Open Mira-tagged password exists. Hosts the list with revoke
 * buttons. Used both when AI Abilities are enabled (revoke + create lives elsewhere) and when
 * disabled (revoke only).
 */
function openmira_render_manage_passwords_section(bool $allow_create_hint = true): void
{
    $mcp_passwords = openmira_get_mcp_passwords();
    if ($mcp_passwords === []) {
        return;
    }

    $dt_format = openmira_get_datetime_format('Y-m-d H:i');
    $count = count($mcp_passwords);
    $open_by_default = $count <= 3;
    /* translators: %d: count of existing application passwords */
    $summary = sprintf(
        _n(
            single: 'Manage existing application password (%d)',
            plural: 'Manage existing application passwords (%d)',
            number: $count,
            domain: 'open-mira',
        ),
        $count,
    );
    ?>
    <details class="openmira-manage-passwords"<?php echo $open_by_default ? ' open' : ''; ?>>
        <summary class="openmira-manage-passwords-summary">
            <?php echo esc_html($summary); ?>
        </summary>
        <div class="openmira-manage-passwords-body">
            <?php if (!$allow_create_hint): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php esc_html_e(
                        'AI Abilities are disabled. These credentials remain valid for WordPress authentication, but the Open Mira MCP endpoint will reject requests until AI Abilities are turned back on.',
                        domain: 'open-mira',
                    ); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'open-mira'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Created', domain: 'open-mira'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'open-mira'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'open-mira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mcp_passwords as $pw): ?>
                        <?php openmira_render_password_row($pw, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Build the paste-to-agent paragraph displayed in Option A of the Connect section.
 *
 * Returns a plain-text block the user can copy and paste into their AI client / agent.
 * The MCP server name uses the same placeholder as the JSON snippets so the live JS
 * preview can swap it in without re-rendering the page.
 */
function openmira_build_paste_to_agent_paragraph(
    string $rest_url,
    string $username,
    string $display_password,
    string $name_placeholder = '__OPENMIRA_MCP_NAME__',
    ?string $password_placeholder = null,
): string {
    $password_value = $password_placeholder ?? $display_password;
    $lines = [
        'I want to add this WordPress site as an MCP server to this AI client.',
        '',
        'Connection details:',
        '- Server URL: ' . $rest_url,
        '- Username: ' . $username,
        '- Application password: ' . $password_value,
        '- Server name to use in the config: ' . $name_placeholder,
        '- Transport: @automattic/mcp-wordpress-remote via npx',
        '',
        'Setup rules:',
        '- Pass credentials ONLY as env vars: WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD. Do NOT use CLI flags like --url or --password (the package ignores them).',
        '- args array must be exactly ["-y", "@automattic/mcp-wordpress-remote@latest"].'
            . (
                openmira_likely_self_signed_https()
                    ? "\n"
                    . '- Also set NODE_TLS_REJECT_UNAUTHORIZED="0" in env (this site uses a local self-signed TLS certificate).'
                    : ''
            ),
        '',
        'Don\'t ask me to confirm choices already specified above. After writing the config, restart or reload the MCP session (most clients require it), then verify by listing the server\'s tools. If it fails, show me the stderr from the npx process before proposing changes.',
        '',
        'If you cannot modify the config of this AI client from here, tell me to expand "Need the JSON config for a specific client?" on the Open Mira Configuration page and copy the snippet manually.',
    ];

    return implode("\n", $lines);
}

/**
 * Build the npx server config array shared across multiple MCP clients.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @return array{command: string, args: list<string>, env: array<string, string>}
 */
function openmira_build_npx_server(string $rest_url, string $username, string $display_password): array
{
    $env = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (openmira_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => $env,
    ];
}

/** @param array<string, mixed> $npx_server */
function openmira_build_zed_json(string $mcp_name, array $npx_server, int $opts): string
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

function openmira_build_opencode_json(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
    int $opts,
): string {
    $environment = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (openmira_likely_self_signed_https()) {
        $environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return (string) json_encode([
        'mcp' => [
            $mcp_name => [
                'type' => 'local',
                'command' => ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
                'environment' => $environment,
            ],
        ],
    ], $opts);
}

function openmira_build_codex_toml(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
        '',
        '[mcp_servers.' . $mcp_name . '.env]',
        'WP_API_URL = ' . $esc($rest_url),
        'WP_API_USERNAME = ' . $esc($username),
        'WP_API_PASSWORD = ' . $esc($display_password),
    ];
    if (openmira_likely_self_signed_https()) {
        $lines[] = 'NODE_TLS_REJECT_UNAUTHORIZED = "0"';
    }
    return implode("\n", $lines);
}

function openmira_build_claude_code_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    $parts = [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($display_password),
    ];
    if (openmira_likely_self_signed_https()) {
        $parts[] = '--env NODE_TLS_REJECT_UNAUTHORIZED=' . $sq('0');
    }
    $parts[] = '-- npx -y @automattic/mcp-wordpress-remote@latest';

    return implode(" \\\n  ", $parts);
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
function openmira_build_configs(string $rest_url, string $username, string $display_password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $npx_server = openmira_build_npx_server($rest_url, $username, $display_password);
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'open-mira');

    $special = [
        'claude-code' => [
            'code' => openmira_build_claude_code_cmd($mcp_name, $rest_url, $username, $display_password),
            'hint' => __('Run in your terminal.', domain: 'open-mira'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => [
            'code' => openmira_build_codex_toml($mcp_name, $rest_url, $username, $display_password),
            'hint' => sprintf($add_to, '<code>config.toml</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => false,
        ],
        'zed' => [
            'code' => openmira_build_zed_json($mcp_name, $npx_server, $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => ['macOS / Linux' => '~/.config/zed/settings.json'],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => openmira_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'open-mira') => 'opencode.json',
                __('Global', domain: 'open-mira') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
    ];

    return array_merge(openmira_build_standard_configs($mcp_servers_json, $vscode_servers_json), $special);
}

/**
 * Build per-client config entries that reuse the standard mcpServers/servers JSON payloads.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function openmira_build_standard_configs(string $mcp_servers_json, string $vscode_servers_json): array
{
    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'open-mira');

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
                __('Global', domain: 'open-mira') => '~/.cursor/mcp.json',
                __('Project', domain: 'open-mira') => '.cursor/mcp.json',
            ],
            'isShell' => false,
        ],
        'vscode' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Workspace', domain: 'open-mira') => '.vscode/mcp.json',
                __('User', domain: 'open-mira') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'open-mira',
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
                __('Via UI', domain: 'open-mira') => __(
                    'Cline sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'open-mira',
                ),
            ],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'open-mira') => '.roo/mcp.json',
                __('Via UI', domain: 'open-mira') => __(
                    'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'open-mira',
                ),
            ],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'open-mira') => '.kilocode/mcp.json',
                __('Via UI', domain: 'open-mira') => __(
                    'Kilo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'open-mira',
                ),
            ],
            'isShell' => false,
        ],
        'github-copilot' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'open-mira') => '.github/copilot/mcp.json',
            ],
            'isShell' => false,
        ],
        'amazon-q' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'open-mira') => '~/.aws/amazonq/mcp.json',
                __('Project', domain: 'open-mira') => '.amazonq/mcp.json',
            ],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'open-mira') => '~/.gemini/settings.json',
                __('Project', domain: 'open-mira') => '.gemini/settings.json',
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
function openmira_render_config_section(string $rest_url, string $username, string $display_password): void
{
    $default_name = openmira_get_mcp_server_name_default();
    $name_placeholder = '__OPENMIRA_MCP_NAME__';
    $pw_slot = '__OPENMIRA_PW_SLOT__';
    $password_is_placeholder = hash_equals('YOUR-APP-PASSWORD', $display_password);
    $configs = openmira_build_configs($rest_url, $username, $display_password, $name_placeholder);
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

    $copied_label = esc_js(__('Copied!', domain: 'open-mira'));
    $paste_paragraph_initial = openmira_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $default_name,
    );
    $paste_paragraph_template = openmira_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $name_placeholder,
        $pw_slot,
    );
    ?>
    <h2 class="openmira-step-heading">
        <span class="openmira-step-badge">3</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'open-mira'); ?>
    </h2>
    <p style="margin:0 0 12px;">
        <?php esc_html_e('Copy the block below and paste it to your AI agent.', domain: 'open-mira'); ?>
    </p>

    <?php if (openmira_likely_self_signed_https()): ?>
        <div class="notice notice-warning inline" style="margin:0 0 12px;">
            <p style="margin:0;">
                <strong><?php esc_html_e('Local HTTPS detected.', domain: 'open-mira'); ?></strong>
                <?php esc_html_e(
                    'Your site uses HTTPS with a certificate that is not publicly trusted (normal for local development). The snippets below include a small flag so your AI client can connect anyway.',
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="openmira-paste-block">
        <div class="openmira-paste-content" id="openmira-paste-content">
            <pre id="openmira-paste-text"><?php echo esc_html($paste_paragraph_initial); ?></pre>
        </div>
        <div class="openmira-paste-actions">
            <button
                type="button"
                class="button-link"
                id="openmira-paste-expand"
                onclick="openmiraToggleExpandPaste(this)"
                aria-expanded="false"
                aria-controls="openmira-paste-content"
            ><?php esc_html_e('Show full text', domain: 'open-mira'); ?></button>
            <button
                type="button"
                class="button button-primary"
                onclick="openmiraCopyPaste(this)"
            ><?php esc_html_e('Copy prompt', domain: 'open-mira'); ?></button>
            <p
                id="openmira-paste-copied-warning"
                style="display:none; margin:0; color:#d63638; font-size:13px; font-weight:600;"
            >
                <?php esc_html_e(
                    "Don't share with anyone: it contains an application password that grants access to this WordPress site.",
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>
    </div>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="openmira-server-name-toggle"
            aria-expanded="false"
            aria-controls="openmira-server-name-field"
            onclick="openmiraToggleServerName(this)"
        ><?php esc_html_e('Change server name (optional)', domain: 'open-mira'); ?></button>
    </p>
    <div id="openmira-server-name-field" hidden style="display:none; margin: 6px 0 14px;">
        <input
            type="text"
            id="openmira-mcp-name"
            value="<?php echo esc_attr($default_name); ?>"
            placeholder="<?php echo esc_attr($default_name); ?>"
            maxlength="25"
            style="width:220px;"
            oninput="openmiraUpdateName(this.value)"
        >
        <p class="description" style="margin:6px 0 0;">
            <?php esc_html_e(
                'Editing here updates the connection text and JSON snippets below in real time. Each AI client config keeps its own name once saved on its side.',
                domain: 'open-mira',
            ); ?>
        </p>
        <div id="openmira-name-warning" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Maximum 25 characters reached. Required for client compatibility.',
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>
        <div id="openmira-name-suggestion" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Tip: keep "open-mira" or "openmira" in the name so you (and your AI agent) can tell this MCP server apart from others.',
                    domain: 'open-mira',
                ); ?>
            </p>
        </div>
    </div>

    <p style="margin:6px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="openmira-manual-toggle"
            aria-expanded="false"
            aria-controls="openmira-manual-config"
            onclick="openmiraToggleManualConfig(this)"
        ><?php esc_html_e('Need the JSON config for a specific client?', domain: 'open-mira'); ?></button>
    </p>

    <div id="openmira-manual-config" hidden style="display:none;">
        <p class="description" style="margin:0 0 12px;">
            <?php esc_html_e(
                'Select your AI client to copy the JSON snippet for its config file.',
                domain: 'open-mira',
            ); ?>
        </p>

        <div class="openmira-client-tabs">
        <?php foreach ($clients as $key => $label): ?>
            <button
                type="button"
                class="openmira-client-tab<?php echo $key === 'claude-code' ? ' active' : ''; ?>"
                onclick="openmiraSetClient('<?php echo esc_js($key); ?>', this)"
            ><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>

    <div class="openmira-tab-content" style="border-radius:4px;">
        <div class="openmira-config-block">
            <pre id="openmira-config-code"></pre>
            <button type="button" class="button openmira-copy-btn" onclick="openmiraCopyConfig(this)"><?php esc_html_e(
                'Copy',
                domain: 'open-mira',
            ); ?></button>
        </div>
        <div id="openmira-config-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
            <div id="openmira-config-hint" style="padding: 10px 16px;"></div>
            <div id="openmira-config-paths" style="padding: 0 16px 10px;"></div>
        </div>
    </div>
    </div>

    <script>
    (function () {
        var configs = <?php echo $configs_json; ?>;
        var client = 'claude-code';
        var defaultName = <?php echo wp_json_encode($default_name); ?>;
        var pasteTemplate = <?php echo wp_json_encode($paste_paragraph_template); ?>;
        var mcpName = <?php echo wp_json_encode($default_name); ?>;
        var namePlaceholder = <?php echo wp_json_encode($name_placeholder); ?>;
        var passwordSentinel = <?php echo wp_json_encode($pw_slot); ?>;
        var passwordValue = <?php echo wp_json_encode($display_password); ?>;
        var passwordIsPlaceholder = <?php echo wp_json_encode($password_is_placeholder); ?>;

        function renderPaste() {
            var text = pasteTemplate.split(namePlaceholder).join(mcpName);
            var container = document.getElementById('openmira-paste-text');
            container.textContent = '';
            var idx = text.indexOf(passwordSentinel);
            if (idx === -1) {
                container.appendChild(document.createTextNode(text));
                return;
            }
            container.appendChild(document.createTextNode(text.substring(0, idx)));
            if (passwordIsPlaceholder) {
                var span = document.createElement('span');
                span.className = 'openmira-placeholder';
                span.textContent = 'YOUR-APP-PASSWORD';
                container.appendChild(span);
            } else {
                container.appendChild(document.createTextNode(passwordValue));
            }
            container.appendChild(document.createTextNode(text.substring(idx + passwordSentinel.length)));
        }

        function render() {
            renderConfig();
            renderPaste();
        }

        function renderConfig() {
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('openmira-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="openmira-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('openmira-config-hint').innerHTML = cfg.hint;

            var pathsEl = document.getElementById('openmira-config-paths');
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

        window.openmiraSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.openmira-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            renderConfig();
        };

        function updateNameWarning(value) {
            var warning = document.getElementById('openmira-name-warning');
            warning.style.display = value.length >= 25 ? 'block' : 'none';

            var suggestion = document.getElementById('openmira-name-suggestion');
            var trimmed = value.trim();
            var lower = trimmed.toLowerCase();
            var missingOpenMira = trimmed.length > 0 && lower.indexOf('openmira') === -1 && lower.indexOf('open-mira') === -1 && lower.indexOf('open mira') === -1;
            suggestion.style.display = missingOpenMira ? 'block' : 'none';
        }

        window.openmiraUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            updateNameWarning(value);
            render();
        };

        window.openmiraToggleServerName = function (btn) {
            var field = document.getElementById('openmira-server-name-field');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                field.style.display = 'none';
                field.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                field.style.display = 'block';
                field.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var input = document.getElementById('openmira-mcp-name');
                if (input) { input.focus(); }
            }
        };

        window.openmiraToggleManualConfig = function (btn) {
            var panel = document.getElementById('openmira-manual-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        window.openmiraToggleExpandPaste = function (btn) {
            var content = document.getElementById('openmira-paste-content');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                content.classList.remove('is-expanded');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = <?php echo wp_json_encode(__('Show full text', domain: 'open-mira')); ?>;
            } else {
                content.classList.add('is-expanded');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = <?php echo wp_json_encode(__('Show less', domain: 'open-mira')); ?>;
            }
        };

        window.openmiraCopyPaste = function (btn) {
            navigator.clipboard.writeText(document.getElementById('openmira-paste-text').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                var warning = document.getElementById('openmira-paste-copied-warning');
                if (warning) { warning.style.display = 'block'; }
                setTimeout(function () {
                    btn.textContent = orig;
                    if (warning) { warning.style.display = 'none'; }
                }, 4000);
            });
        };

        window.openmiraCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('openmira-config-code').textContent).then(function () {
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

function openmira_render_mcp_dependency_inline_notice(?WP_Error $dependency_error): void
{
    if ($dependency_error === null) {
        return;
    }

    ?>
    <div class="openmira-mcp-error-panel" role="alert">
        <h2><?php esc_html_e('Open Mira cannot expose MCP', domain: 'open-mira'); ?></h2>
        <p><?php echo esc_html($dependency_error->get_error_message()); ?></p>
    </div>
    <?php
}

function openmira_render_enable_prompt(?WP_Error $dependency_error): void
{
    if (openmira_is_enabled() || $dependency_error !== null) {
        return;
    }

    ?>
    <p style="color:#666; font-size:14px;">
        <?php esc_html_e(
            'Enable AI Abilities above to create application passwords and connect an MCP client.',
            domain: 'open-mira',
        ); ?>
    </p>
    <?php
}

/**
 * Render the connect / setup dashboard page.
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_render_connect_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $mcp_dependency_error = openmira_get_mcp_dependency_error();
    $toggle_saved = openmira_handle_toggle_enabled();
    $enabled = openmira_is_enabled();
    $mcp_ready = $enabled && $mcp_dependency_error === null;

    $password_result = $mcp_ready ? openmira_handle_create_password() : null;
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;

    $existing_result = $mcp_ready ? openmira_handle_use_existing_password() : null;
    $existing_error = is_wp_error($existing_result) ? $existing_result : null;
    $existing_password = is_string($existing_result) ? $existing_result : null;

    $result_message = match ($_GET['openmira_result'] ?? null) {
        'revoked' => __('Application password revoked.', domain: 'open-mira'),
        default => null,
    };

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $rest_url = rest_url('mcp/openmira');
    $display_password = $new_password ?? $existing_password ?? 'YOUR-APP-PASSWORD';

    $copied_label = esc_js(__('Copied!', domain: 'open-mira'));

    ?>
    <style>
        .openmira-connect-section {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 6px;
            padding: 20px 24px;
            margin: 0 0 20px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.03);
        }
        .openmira-step-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 12px;
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
        }
        .openmira-step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #1d2327;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            flex: 0 0 auto;
        }
        .openmira-config-block { position: relative; }
        .openmira-config-block pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 16px;
            border-radius: 0 4px 0 0;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
        }
        .openmira-copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .openmira-password-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff8e1;
            border: 1px solid #f0c040;
            border-radius: 4px;
            padding: 12px 16px;
            margin: 12px 0;
        }
        .openmira-password-value {
            font-family: monospace;
            font-size: 18px;
            letter-spacing: 1px;
            font-weight: bold;
        }
        .openmira-client-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .openmira-client-tab {
            padding: 5px 14px;
            border: 1px solid #c3c4c7;
            background: #f6f7f7;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
        }
        .openmira-client-tab.active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
            font-weight: 600;
        }
        .openmira-tab-content { border: 1px solid #c3c4c7; border-radius: 4px; }
        .openmira-revoke-btn { color: #d63638 !important; border-color: #d63638 !important; }
        .openmira-placeholder { background: #d63638; color: #fff; padding: 1px 4px; border-radius: 3px; }
        .openmira-mcp-error-panel {
            background: #fff;
            border-left: 4px solid #d63638;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            margin: 16px 0 24px;
            padding: 12px 16px;
        }
        .openmira-mcp-error-panel h2 {
            color: #1d2327;
            font-size: 16px;
            line-height: 1.4;
            margin: 0 0 8px;
        }
        .openmira-mcp-error-panel p {
            font-size: 14px;
            margin: 0;
        }
        .openmira-production-warning {
            background: #fff8e1;
            border-left: 4px solid #f0c040;
            padding: 12px 16px;
            margin: 12px 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .openmira-production-warning p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
            flex: 1 1 auto;
        }
        .openmira-paste-block {
            margin: 12px 0;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            overflow: hidden;
        }
        .openmira-paste-header {
            background: #1d2327;
            color: #fff;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .openmira-paste-content {
            position: relative;
            background: #f6f7f7;
        }
        .openmira-paste-content pre {
            background: transparent;
            color: #1d2327;
            padding: 16px;
            border: none;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
            max-height: 6.5em;
            overflow: hidden;
        }
        .openmira-paste-content.is-expanded pre {
            max-height: none;
            overflow: visible;
        }
        .openmira-paste-content:not(.is-expanded)::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 32px;
            background: linear-gradient(to bottom, rgba(246, 247, 247, 0), rgba(246, 247, 247, 1));
            pointer-events: none;
        }
        .openmira-paste-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 14px 14px;
            background: #fff;
            border-top: 1px solid #c3c4c7;
        }
        .openmira-manage-passwords {
            margin: 20px 0 0;
            border-top: 1px solid #e0e0e0;
            padding-top: 16px;
        }
        .openmira-manage-passwords-summary {
            font-weight: 600;
            cursor: pointer;
            list-style: none;
            color: #1d2327;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }
        .openmira-manage-passwords-summary::-webkit-details-marker { display: none; }
        .openmira-manage-passwords-summary::before {
            content: '▸';
            color: #646970;
            transition: transform 0.15s;
        }
        .openmira-manage-passwords[open] .openmira-manage-passwords-summary::before {
            transform: rotate(90deg);
        }
        .openmira-manage-passwords-body {
            padding-top: 12px;
        }
    </style>

    <?php openmira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('Configuration', domain: 'open-mira'); ?></h1>

        <?php openmira_render_mcp_dependency_inline_notice($mcp_dependency_error); ?>

        <?php if ($toggle_saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php

            esc_html_e('Settings saved.', domain: 'open-mira');
            ?></p></div>
        <?php endif; ?>

        <?php openmira_render_production_warning(); ?>

        <div class="openmira-connect-section">
            <?php openmira_render_enable_toggle(); ?>
        </div>

        <?php openmira_render_enable_prompt($mcp_dependency_error); ?>
        <?php if ($mcp_ready): ?>
            <?php if ($create_error !== null): ?>
                <div class="notice notice-error"><p><?php

                echo esc_html($create_error->get_error_message());
                ?></p></div>
            <?php endif; ?>

            <?php if ($result_message !== null): ?>
                <div class="notice notice-success is-dismissible"><p><?php

                echo esc_html($result_message);
                ?></p></div>
            <?php endif; ?>

            <div class="openmira-connect-section">
                <?php openmira_render_password_step($new_password, $existing_password, $existing_error); ?>
                <?php openmira_render_manage_passwords_section(allow_create_hint: true); ?>
            </div>

            <?php if ($new_password !== null || $existing_password !== null): ?>
                <div class="openmira-connect-section">
                    <?php openmira_render_config_section($rest_url, $username, $display_password); ?>
                </div>
            <?php endif; ?>
        <?php elseif (openmira_get_mcp_passwords() !== []): ?>
            <div class="openmira-connect-section">
                <h2 class="openmira-step-heading">
                    <span class="openmira-step-badge">2</span>
                    <?php esc_html_e('Application Password', domain: 'open-mira'); ?>
                </h2>
                <?php openmira_render_manage_passwords_section(allow_create_hint: false); ?>
            </div>
        <?php endif; ?>

    </div>

    <script>
    function openmiraCopy(id, btn) {
        var text = document.getElementById(id).textContent;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '<?php echo $copied_label; ?>';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    }
    function openmiraTogglePasswordName(btn) {
        var field = document.getElementById('openmira-password-name-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('openmira-password-name');
            if (input) { input.focus(); }
        }
    }
    function openmiraToggleUseExisting(btn) {
        var field = document.getElementById('openmira-use-existing-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('openmira-existing-password');
            if (input) { input.focus(); }
        }
    }
    </script>
    <?php
}
