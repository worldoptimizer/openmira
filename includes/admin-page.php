<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Collects every public MCP tool ability registered on the site, grouped by source.
 *
 * The source label is resolved per-ability via the `openmira_ability_source_label`
 * filter (default: "Open Mira"), so add-ons can contribute rows under their own
 * heading. Within a group, rows are sorted by category then name. Groups are
 * returned with the default source first, other sources sorted alphabetically.
 *
 * @return array<string, list<array{name: string, category: string, description: string}>>
 */
function openmira_collect_public_abilities(): array
{
    $default_source = __('Open Mira', domain: 'open-mira');
    $groups = [];
    foreach (wp_get_abilities() as $ability) {
        $name = $ability->get_name();
        if (!str_starts_with($name, 'openmira/')) {
            continue;
        }
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
            continue;
        }
        $category_slug = $ability->get_category();
        $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;
        /** @var string $source */
        $source = apply_filters('openmira_ability_source_label', $default_source, $ability);
        $groups[$source] ??= [];
        $groups[$source][] = [
            'name' => $name,
            'category' => $category !== null ? $category->get_label() : $category_slug,
            'description' => $ability->get_description(),
        ];
    }
    foreach ($groups as $source => $rows) {
        usort(
            $rows,
            static fn(array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']],
        );
        $groups[$source] = $rows;
    }

    $sorted = [];
    if (array_key_exists($default_source, $groups)) {
        $sorted[$default_source] = $groups[$default_source];
        unset($groups[$default_source]);
    }
    ksort($groups);
    return $sorted + $groups;
}

function openmira_handle_sandbox_actions()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $action = $_GET['action'] ?? null;
    $file_param = $_GET['file'] ?? null;

    if (!is_string($action) || !is_string($file_param)) {
        return;
    }

    $file = basename($file_param);
    if (!check_admin_referer('openmira_manage_file_' . $file)) {
        return;
    }

    $path = openmira_get_sandbox_dir(true) . $file;
    if (!file_exists($path)) {
        return;
    }

    $result = match ($action) {
        'delete' => unlink($path),
        'disable' => str_ends_with($file, '.php') && rename($path, $path . '.disabled'),
        'enable' => str_ends_with($file, '.disabled') && rename($path, substr($path, offset: 0, length: -9)),
        'exit_safe_mode' => $file === '.crashed' && unlink($path),
        default => false,
    };

    if ($result) {
        wp_safe_redirect(admin_url('admin.php?page=openmira-sandbox&openmira_result=' . $action));
        exit();
    }
}

function openmira_render_sandbox_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $result_message = match ($_GET['openmira_result'] ?? null) {
        'delete' => __('File deleted.', domain: 'open-mira'),
        'disable' => __('File disabled.', domain: 'open-mira'),
        'enable' => __('File enabled.', domain: 'open-mira'),
        'exit_safe_mode' => __(
            'Safe mode deactivated. Sandbox files will load on the next request.',
            domain: 'open-mira',
        ),
        default => null,
    };
    $sandbox_dir = openmira_get_sandbox_dir(true);
    $is_crashed = file_exists($sandbox_dir . '.crashed');

    ?>
    <?php openmira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('Sandbox Files', domain: 'open-mira'); ?></h1>
        <?php if ($result_message !== null) { ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php } ?>
        <?php if ($is_crashed) { ?>
            <div class="notice notice-error" style="padding: 12px 16px;">
                <p>
                    <strong><?php esc_html_e('Safe mode is active.', domain: 'open-mira'); ?></strong>
                    <?php esc_html_e(
                        'A sandbox file caused a fatal error on a previous request. All sandbox files are suspended until you fix or delete the broken file and exit safe mode.',
                        domain: 'open-mira',
                    ); ?>
                </p>
                <p>
                    <?php

                    $exit_url = wp_nonce_url(
                        admin_url('admin.php?page=openmira-sandbox&action=exit_safe_mode&file=.crashed'),
                        action: 'openmira_manage_file_.crashed',
                    );
                    ?>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary"><?php esc_html_e(
                        'Exit Safe Mode',
                        domain: 'open-mira',
                    ); ?></a>
                </p>
            </div>
        <?php } ?>
        <?php openmira_render_sandbox_table(); ?>
    </div>
    <?php
}

function openmira_render_sandbox_table(): void
{ ?>
    <p><?php esc_html_e(
        'Manage the files generated by AI agents in the sandbox directory.',
        domain: 'open-mira',
    ); ?></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Filename', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Status', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Last Modified', domain: 'open-mira'); ?></th>
                <th><?php esc_html_e('Actions', domain: 'open-mira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php

            $sandbox_dir = openmira_get_sandbox_dir(true);
            $is_crashed = file_exists($sandbox_dir . '.crashed');
            $scanned_files = is_dir($sandbox_dir) ? scandir($sandbox_dir) : false;
            $files = $scanned_files !== false ? array_diff($scanned_files, ['.', '..', '.loading', '.crashed']) : [];

            if ($files === []) {
                echo '<tr><td colspan="4">' . esc_html__('No sandbox files found.', domain: 'open-mira') . '</td></tr>';
            }

            $format = openmira_get_datetime_format();

            foreach ($files as $file) {
                if (is_dir($sandbox_dir . $file)) {
                    continue;
                }
                $path = $sandbox_dir . $file;
                $is_disabled = str_ends_with($file, '.disabled');

                $status = match (true) {
                    $is_disabled => __('Disabled', domain: 'open-mira'),
                    $is_crashed => __('Suspended', domain: 'open-mira'),
                    default => __('Enabled', domain: 'open-mira'),
                };

                $mtime = filemtime($path);
                $wp_date = $mtime !== false ? wp_date($format, $mtime) : false;
                $modified = $wp_date !== false ? $wp_date : __('Unknown', domain: 'open-mira');

                $base_url = admin_url('admin.php?page=openmira-sandbox');

                $delete_url = wp_nonce_url(
                    $base_url . '&action=delete&file=' . urlencode($file),
                    'openmira_manage_file_' . $file,
                );
                $toggle_action = $is_disabled ? 'enable' : 'disable';
                $toggle_url = wp_nonce_url(
                    $base_url . '&action=' . $toggle_action . '&file=' . urlencode($file),
                    'openmira_manage_file_' . $file,
                );

                ?>
                <tr>
                    <td><strong><?php echo esc_html($file); ?></strong></td>
                    <td><?php echo esc_html($status); ?></td>
                    <td><?php echo esc_html($modified); ?></td>
                    <td>
                        <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small"><?php echo
                            $is_disabled
                                ? esc_html__('Enable', domain: 'open-mira')
                                : esc_html__('Disable', domain: 'open-mira')
                        ; ?></a>
                        <a href="<?php echo
                            esc_url($delete_url)
                        ; ?>" class="button button-small" onclick="return confirm('<?php echo
                            esc_js(__('Are you sure you want to delete this file?', domain: 'open-mira'))
                        ; ?>');" style="color: #d63638; border-color: #d63638;"><?php esc_html_e(
                            'Delete',
                            domain: 'open-mira',
                        ); ?></a>
                    </td>
                </tr>
                <?php
            }
            ?>
        </tbody>
    </table>
    <?php }

function openmira_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $ability_groups = openmira_collect_public_abilities();
    ?>
    <?php openmira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Abilities', domain: 'open-mira'); ?></h1>
        <p><?php printf(
            /* translators: %s: link to the Configuration page */
            esc_html__(
                'These public MCP tools are exposed to AI agents when AI Abilities are enabled on the %s page. Site owners can restrict individual abilities with the openmira_ability_capability filter.',
                domain: 'open-mira',
            ),
            '<a href="'
            . esc_url(admin_url('admin.php?page=openmira-connect'))
            . '">'
            . esc_html__('Configuration', domain: 'open-mira')
            . '</a>',
        ); ?></p>
        <?php foreach ($ability_groups as $source => $abilities): ?>
            <h3 style="margin-top:1.5em;"><?php echo esc_html($source); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:260px;"><?php esc_html_e('Ability', domain: 'open-mira'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Category', domain: 'open-mira'); ?></th>
                        <th><?php esc_html_e('Description', domain: 'open-mira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($abilities as $ability): ?>
                        <tr>
                            <td><code><?php echo esc_html($ability['name']); ?></code></td>
                            <td><?php echo esc_html($ability['category']); ?></td>
                            <td><?php echo esc_html($ability['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>
    <?php
}
