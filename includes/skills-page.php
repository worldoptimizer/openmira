<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Admin UI for Open Mira Skills.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action(
    'admin_menu',
    static function (): void {
        add_submenu_page(
            parent_slug: 'openmira-connect',
            page_title: __('Skills', domain: 'open-mira'),
            menu_title: __('Skills', domain: 'open-mira'),
            capability: 'manage_options',
            menu_slug: 'openmira-skills',
            callback: 'openmira_render_skills_page',
        );
    },
    priority: 11,
);

add_action('admin_enqueue_scripts', static function (string $hook_suffix): void {
    if ($hook_suffix !== 'open-mira_page_openmira-skills') {
        return;
    }

    openmira_enqueue_admin_list_styles();
    $action = openmira_skill_request_string($_GET['skill_action'] ?? null);
    if ($action === 'add' || $action === 'edit') {
        openmira_enqueue_markdown_editor('openmira-skill-body');
    }
});

/**
 * Render the Skills admin page.
 */
function openmira_render_skills_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage Open Mira Skills.', domain: 'open-mira'));
    }

    $skills = openmira_get_skills();
    $action = openmira_skill_request_string($_GET['skill_action'] ?? null);
    $skill_id = openmira_skill_request_string($_GET['skill_id'] ?? null);

    openmira_render_admin_header();
    ?>
    <div class="wrap openmira-skills-page">
        <h1><?php esc_html_e('Open Mira Skills', domain: 'open-mira'); ?></h1>
        <p><?php echo
            wp_kses_post(
                'Skills are MCP Prompts loaded from plugin-bundled <code>includes/skills/&lt;id&gt;/SKILL.md</code> files and custom <code>openmira_skill</code> posts. Built-in filesystem skills are read-only; customize one to create a CPT-backed copy with native WordPress revisions.',
            )
        ; ?></p>
        <?php openmira_render_skill_notice(); ?>
        <?php openmira_render_legacy_skill_files_notice(); ?>
        <?php

        if ($action === 'add') {
            openmira_render_skill_edit_form(null);
            echo '</div>';
            return;
        }
        if ($action === 'edit') {
            $skill = $skills[$skill_id] ?? null;
            if (is_array($skill) && $skill['source'] === 'cpt') {
                openmira_render_skill_edit_form($skill);
            } else {
                openmira_render_skill_error_notice(__(
                    'Only user skills can be edited. Customize a built-in skill first.',
                    domain: 'open-mira',
                ));
                openmira_render_skills_list($skills);
            }
            echo '</div>';
            return;
        }
        if ($action === 'view') {
            $skill = $skills[$skill_id] ?? null;
            if (is_array($skill)) {
                openmira_render_skill_view($skill);
            } else {
                openmira_render_skill_error_notice(__('Skill not found.', domain: 'open-mira'));
                openmira_render_skills_list($skills);
            }
            echo '</div>';
            return;
        }

        openmira_render_skills_list($skills);
        ?>
    </div>
    <?php
}

/**
 * Render page notices.
 */
function openmira_render_skill_notice(): void
{
    $error = is_string($_GET['openmira_skill_error'] ?? null)
        ? sanitize_text_field(wp_unslash($_GET['openmira_skill_error']))
        : '';
    if ($error !== '') {
        openmira_render_skill_error_notice($error);
        return;
    }

    $result = is_string($_GET['openmira_skill_result'] ?? null)
        ? sanitize_text_field(wp_unslash($_GET['openmira_skill_result']))
        : '';
    if ($result === '') {
        return;
    }

    $message = match (true) {
        $result === 'created' => __('Skill created.', domain: 'open-mira'),
        $result === 'updated' => __('Skill updated.', domain: 'open-mira'),
        $result === 'trashed' => __('Skill moved to trash.', domain: 'open-mira'),
        $result === 'restored' => __('Skill restored.', domain: 'open-mira'),
        $result === 'deleted' => __('Skill permanently deleted.', domain: 'open-mira'),
        $result === 'customized' => __(
            'Built-in skill copied to a custom skill. You can edit it now.',
            domain: 'open-mira',
        ),
        $result === 'enabled' => __('Skill prompt enabled.', domain: 'open-mira'),
        $result === 'disabled' => __('Skill prompt disabled.', domain: 'open-mira'),
        str_starts_with($result, 'imported-') => sprintf(
            /* translators: %s is an import result summary. */
            __('Skills import complete: %s.', domain: 'open-mira'),
            $result,
        ),
        default => __('Skill action complete.', domain: 'open-mira'),
    };
    ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php openmira_render_skill_import_summary(); ?>
    <?php
}

/**
 * Render a per-file import summary after PRG redirects.
 */
function openmira_render_skill_import_summary(): void
{
    $key = is_string($_GET['openmira_skill_import_key'] ?? null)
        ? sanitize_text_field(wp_unslash($_GET['openmira_skill_import_key']))
        : '';
    if ($key === '' || !function_exists('openmira_take_skill_import_results')) {
        return;
    }

    $result = openmira_take_skill_import_results($key);
    $file_results = is_array($result['file_results'] ?? null) ? $result['file_results'] : [];
    if ($file_results === []) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible">
        <p><strong><?php esc_html_e('Per-file import results', domain: 'open-mira'); ?></strong></p>
        <ul class="openmira-admin-result-list">
            <?php foreach ($file_results as $file_result): ?>
                <li>
                    <code><?php echo esc_html((string) ($file_result['file'] ?? '')); ?></code>
                    —
                    <?php echo esc_html((string) ($file_result['message'] ?? $file_result['status'] ?? '')); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/**
 * Render a one-version deprecation notice for legacy filesystem user skills.
 */
function openmira_render_legacy_skill_files_notice(): void
{
    if (!function_exists('openmira_has_legacy_user_skill_files') || !openmira_has_legacy_user_skill_files()) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p><?php echo
            wp_kses_post(sprintf(
                /* translators: %s is the legacy skills directory. */
                __(
                    'Legacy filesystem skills were migrated into CPT storage. Files under <code>%s</code> are no longer loaded by Open Mira and will be ignored fully in a future release.',
                    domain: 'open-mira',
                ),
                esc_html(openmira_user_skills_dir()),
            ))
        ; ?></p>
    </div>
    <?php
}

/**
 * Render an error notice.
 */
function openmira_render_skill_error_notice(string $message): void
{ ?>
    <div class="notice notice-error is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php }

/**
 * Render skills list with toolbar.
 *
 * @param array<string, array<string, mixed>> $skills
 */
function openmira_render_skills_list(array $skills): void
{
    $user_skills = array_filter($skills, static fn(array $skill): bool => $skill['source'] === 'cpt');
    $built_in_skills = array_filter($skills, static fn(array $skill): bool => $skill['source'] === 'filesystem');
    $trashed_skills = openmira_get_trashed_skill_rows();
    $filter = openmira_skill_request_string($_GET['skill_status'] ?? null);
    if (!in_array($filter, ['all', 'active', 'trashed'], true)) {
        $filter = 'all';
    }
    ?>
    <div class="openmira-admin-toolbar openmira-admin-filter-toolbar">
        <?php foreach ([
            'all' => __('All', domain: 'open-mira'),
            'active' => __('Active', domain: 'open-mira'),
            'trashed' => __('Trashed', domain: 'open-mira'),
        ] as $status => $label): ?>
            <a class="<?php echo
                esc_attr($filter === $status ? 'button button-primary' : 'button')
            ; ?>" href="<?php echo
                esc_url(add_query_arg(['page' => 'openmira-skills', 'skill_status' => $status], admin_url('admin.php')))
            ; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>
        <?php if ($filter !== 'trashed'): ?>
            <h2><?php esc_html_e('Custom Skills', domain: 'open-mira'); ?></h2>
            <div class="openmira-admin-toolbar">
                <a class="button button-primary" href="<?php echo
                    esc_url(add_query_arg([
                        'page' => 'openmira-skills',
                        'skill_action' => 'add',
                    ], admin_url('admin.php')))
                ; ?>"><?php esc_html_e('Add Skill', domain: 'open-mira'); ?></a>
                <button type="button" class="button" onclick="document.getElementById('openmira-skill-import-panel').toggleAttribute('hidden');"><?php esc_html_e(
                    'Import',
                    domain: 'open-mira',
                ); ?></button>
                <?php if ($user_skills !== []): ?>
                    <a class="button" href="<?php echo
                        esc_url(wp_nonce_url(
                            admin_url('admin.php?page=openmira-skills&openmira_skill_export=1'),
                            action: 'openmira_skill_export',
                        ))
                    ; ?>"><?php esc_html_e('Export all user skills', domain: 'open-mira'); ?></a>
                <?php endif; ?>
            </div>
            <?php openmira_render_skill_import_panel(); ?>
            <?php openmira_render_skill_table($user_skills, true); ?>
            <h2><?php esc_html_e('Built-in Skills', domain: 'open-mira'); ?></h2>
            <?php openmira_render_skill_table($built_in_skills, false); ?>
        <?php endif; ?>
        <?php if ($filter !== 'active'): ?>
            <h2><?php esc_html_e('Trashed Skills', domain: 'open-mira'); ?></h2>
            <?php openmira_render_skill_table($trashed_skills, true, trashed: true); ?>
        <?php endif; ?>
    <?php
}

/**
 * Render the import form.
 */
function openmira_render_skill_import_panel(): void
{ ?>
    <div id="openmira-skill-import-panel" class="card openmira-admin-import-panel" hidden>
        <h2><?php esc_html_e('Import Skills', domain: 'open-mira'); ?></h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo
            esc_url(admin_url('admin.php?page=openmira-skills'))
        ; ?>">
            <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
            <input type="hidden" name="openmira_skill_action" value="import">
            <div class="openmira-admin-form-row">
                <label for="openmira-skill-import-file"><strong><?php esc_html_e(
                    'File',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <input id="openmira-skill-import-file" type="file" name="skill_import_file[]" accept=".md,.markdown,.zip" multiple required>
                <p class="description"><?php esc_html_e(
                    'Choose one ZIP archive or one or more Markdown skill files.',
                    domain: 'open-mira',
                ); ?></p>
            </div>
            <div class="openmira-admin-form-row">
                <label for="openmira-single-skill-id"><strong><?php esc_html_e(
                    'Skill ID for single SKILL.md imports',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <input id="openmira-single-skill-id" class="regular-text" name="single_skill_id" pattern="^[a-z0-9][-a-z0-9._]{0,79}$">
                <p class="description"><?php esc_html_e(
                    'Only used when uploading one file named SKILL.md.',
                    domain: 'open-mira',
                ); ?></p>
            </div>
            <p>
                <label><input type="checkbox" name="skip_existing" value="1"> <?php esc_html_e(
                    'Skip existing user skills',
                    domain: 'open-mira',
                ); ?></label>
            </p>
            <?php submit_button(
                text: __('Import Skills', domain: 'open-mira'),
                type: 'secondary',
                name: 'submit',
                wrap: false,
            ); ?>
        </form>
    </div>
    <?php }

/**
 * Render a skills table.
 *
 * @param array<string, array<string, mixed>> $skills
 */
function openmira_render_skill_table(array $skills, bool $is_user_group, bool $trashed = false): void
{ ?>
    <table class="wp-list-table widefat fixed striped openmira-admin-skills-table">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Title', domain: 'open-mira'); ?></th>
                <th scope="col"><?php esc_html_e('ID', domain: 'open-mira'); ?></th>
                <th scope="col"><?php esc_html_e('Source', domain: 'open-mira'); ?></th>
                <th scope="col"><?php esc_html_e('Prompt name', domain: 'open-mira'); ?></th>
                <th scope="col"><?php esc_html_e('Enable prompt', domain: 'open-mira'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', domain: 'open-mira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($skills === []): ?>
                <tr>
                    <td colspan="6"><?php echo
                        esc_html(
                            $is_user_group
                                ? (
                                    $trashed
                                        ? __('No trashed skills.', domain: 'open-mira')
                                        : __('No custom skills yet.', domain: 'open-mira')
                                )
                                : __('No built-in skills found.', domain: 'open-mira'),
                        )
                    ; ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($skills as $skill): ?>
                    <?php openmira_render_skill_row($skill, trashed: $trashed); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php }

/**
 * Render one skill table row.
 *
 * @param array<string, mixed> $skill
 */
function openmira_render_skill_row(array $skill, bool $trashed = false): void
{
    $skill_id = (string) $skill['id'];
    $source = (string) $skill['source'];
    $overrides = ($skill['overrides_built_in'] ?? false) === true;
    $view_url = add_query_arg([
        'page' => 'openmira-skills',
        'skill_action' => 'view',
        'skill_id' => $skill_id,
    ], admin_url('admin.php'));
    $edit_url = add_query_arg([
        'page' => 'openmira-skills',
        'skill_action' => 'edit',
        'skill_id' => $skill_id,
    ], admin_url('admin.php'));
    ?>
    <tr>
        <td>
            <strong>
                <?php if ($trashed): ?>
                    <?php echo esc_html((string) $skill['title']); ?>
                <?php else: ?>
                    <a href="<?php echo esc_url($source === 'cpt' ? $edit_url : $view_url); ?>">
                        <?php echo esc_html((string) $skill['title']); ?>
                    </a>
                <?php endif; ?>
            </strong>
            <p class="description"><?php echo esc_html((string) $skill['description']); ?></p>
        </td>
        <td><code><?php echo esc_html($skill_id); ?></code></td>
        <td>
            <span class="openmira-admin-source-badge openmira-admin-source-badge--<?php echo
                esc_attr($overrides ? 'override' : $source)
            ; ?>">
                <?php echo esc_html(openmira_skill_source_label($source, $overrides)); ?>
            </span>
        </td>
        <td><code><?php echo esc_html((string) $skill['prompt_name']); ?></code></td>
        <td class="openmira-admin-table-actions">
            <?php if ($source === 'cpt' && !$trashed): ?>
                <?php openmira_render_skill_prompt_toggle_form($skill_id, ($skill['enabled'] ?? true) !== false); ?>
            <?php elseif ($trashed): ?>
                <?php esc_html_e('Not registered', domain: 'open-mira'); ?>
            <?php else: ?>
                <?php esc_html_e('Always enabled', domain: 'open-mira'); ?>
            <?php endif; ?>
        </td>
        <td class="openmira-admin-table-actions">
            <?php if ($source === 'cpt' && $trashed): ?>
                <?php openmira_render_skill_restore_form($skill_id); ?>
                <span class="openmira-admin-action-separator">|</span>
                <?php openmira_render_skill_delete_form($skill_id, permanent: true); ?>
            <?php elseif ($source === 'cpt'): ?>
                <a class="openmira-admin-action-link" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                    'Edit',
                    domain: 'open-mira',
                ); ?></a>
                <span class="openmira-admin-action-separator">|</span>
                <a class="openmira-admin-action-link" href="<?php echo esc_url($view_url); ?>"><?php esc_html_e(
                    'View',
                    domain: 'open-mira',
                ); ?></a>
                <span class="openmira-admin-action-separator">|</span>
                <?php openmira_render_skill_revisions_link((int) ($skill['post_id'] ?? 0)); ?>
                <span class="openmira-admin-action-separator">|</span>
                <a class="openmira-admin-action-link" href="<?php echo
                    esc_url(wp_nonce_url(
                        admin_url(
                            'admin.php?page=openmira-skills&openmira_skill_export=1&skill_id='
                                . rawurlencode($skill_id),
                        ),
                        action: 'openmira_skill_export',
                    ))
                ; ?>"><?php esc_html_e('Export', domain: 'open-mira'); ?></a>
                <span class="openmira-admin-action-separator">|</span>
                <?php openmira_render_skill_trash_form($skill_id); ?>
            <?php else: ?>
                <?php openmira_render_skill_customize_form($skill_id); ?>
                <span class="openmira-admin-action-separator">|</span>
                <a class="openmira-admin-action-link" href="<?php echo esc_url($view_url); ?>"><?php esc_html_e(
                    'View',
                    domain: 'open-mira',
                ); ?></a>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/**
 * Render a view-only skill preview.
 *
 * @param array<string, mixed> $skill
 */
function openmira_render_skill_view(array $skill): void
{
    $source = (string) $skill['source'];
    $overrides = ($skill['overrides_built_in'] ?? false) === true;
    ?>
    <h2><?php echo esc_html((string) $skill['title']); ?></h2>
    <p>
        <span class="openmira-admin-source-badge openmira-admin-source-badge--<?php echo
            esc_attr($overrides ? 'override' : $source)
        ; ?>">
            <?php echo esc_html(openmira_skill_source_label($source, $overrides)); ?>
        </span>
        <strong><?php esc_html_e('ID:', domain: 'open-mira'); ?></strong>
        <code><?php echo esc_html((string) $skill['id']); ?></code>
        <strong><?php esc_html_e('Prompt:', domain: 'open-mira'); ?></strong>
        <code><?php echo esc_html((string) $skill['prompt_name']); ?></code>
        <strong><?php esc_html_e('Enabled:', domain: 'open-mira'); ?></strong>
        <?php echo
            esc_html(
                ($skill['enabled'] ?? true) !== false ? __('Yes', domain: 'open-mira') : __('No', domain: 'open-mira'),
            )
        ; ?>
    </p>
    <p><?php echo esc_html((string) $skill['description']); ?></p>
    <pre class="openmira-admin-skill-preview"><?php echo esc_html((string) $skill['body']); ?></pre>
    <p>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=openmira-skills')); ?>"><?php esc_html_e(
            'Back to Skills',
            domain: 'open-mira',
        ); ?></a>
    </p>
    <?php
}

/**
 * Render create/edit form.
 *
 * @param array<string, mixed>|null $skill
 */
function openmira_render_skill_edit_form(?array $skill): void
{
    $editing = $skill !== null;
    $skill_id = $editing ? (string) ($skill['id'] ?? '') : '';
    $title = $editing ? (string) ($skill['title'] ?? '') : '';
    $description = $editing ? (string) ($skill['description'] ?? '') : '';
    $body = $editing ? (string) ($skill['body'] ?? '') : '';
    ?>
    <h2><?php echo
        esc_html($editing ? __('Edit Skill', domain: 'open-mira') : __('Add Skill', domain: 'open-mira'))
    ; ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=openmira-skills')); ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="save">
        <fieldset class="openmira-admin-fieldset">
            <legend><?php esc_html_e('Identity', domain: 'open-mira'); ?></legend>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="openmira-skill-id"><?php esc_html_e(
                        'Skill ID',
                        domain: 'open-mira',
                    ); ?></label></th>
                    <td>
                        <input id="openmira-skill-id" class="regular-text" name="skill_id" pattern="^[a-z0-9][-a-z0-9._]{0,79}$" required value="<?php echo
                            esc_attr($skill_id)
                        ; ?>" <?php disabled($editing); ?>>
                        <?php if ($editing): ?>
                            <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e(
                            'Use lowercase letters, numbers, dots, underscores, and hyphens. Maximum 80 characters.',
                            domain: 'open-mira',
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="openmira-skill-title"><?php esc_html_e(
                        'Title',
                        domain: 'open-mira',
                    ); ?></label></th>
                    <td><input id="openmira-skill-title" class="large-text" name="skill_title" required value="<?php echo
                        esc_attr($title)
                    ; ?>"></td>
                </tr>
            </table>
        </fieldset>
        <fieldset class="openmira-admin-fieldset">
            <legend><?php esc_html_e('Metadata', domain: 'open-mira'); ?></legend>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="openmira-skill-description"><?php esc_html_e(
                        'Description',
                        domain: 'open-mira',
                    ); ?></label></th>
                    <td><input id="openmira-skill-description" class="large-text" name="skill_description" required value="<?php echo
                        esc_attr($description)
                    ; ?>"></td>
                </tr>
            </table>
        </fieldset>
        <fieldset class="openmira-admin-fieldset">
            <legend><?php esc_html_e('Content', domain: 'open-mira'); ?></legend>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="openmira-skill-body"><?php esc_html_e(
                        'Body',
                        domain: 'open-mira',
                    ); ?></label></th>
                    <td>
                        <textarea id="openmira-skill-body" class="large-text code" rows="18" name="skill_body"><?php echo
                            esc_textarea($body)
                        ; ?></textarea>
                        <p class="description"><?php esc_html_e(
                            'Markdown prompt body. Maximum 64 KB.',
                            domain: 'open-mira',
                        ); ?></p>
                    </td>
                </tr>
            </table>
        </fieldset>
        <p class="openmira-admin-form-actions">
            <?php submit_button(
                text: $editing ? __('Update Skill', domain: 'open-mira') : __('Create Skill', domain: 'open-mira'),
                type: 'primary',
                name: 'submit',
                wrap: false,
            ); ?>
            <a class="button button-secondary" href="<?php echo
                esc_url(admin_url('admin.php?page=openmira-skills'))
            ; ?>"><?php esc_html_e('Cancel', domain: 'open-mira'); ?></a>
        </p>
    </form>
    <?php
}

/**
 * Render trash form.
 */
function openmira_render_skill_trash_form(string $skill_id): void
{ ?>
    <form class="openmira-admin-inline-form" method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills'))
    ; ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="trash">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button-link-delete openmira-admin-action-link" onclick="return confirm('<?php echo
            esc_js(__('Move this user skill to trash?', domain: 'open-mira'))
        ; ?>');"><?php esc_html_e('Trash', domain: 'open-mira'); ?></button>
    </form>
    <?php }

/**
 * Render restore form.
 */
function openmira_render_skill_restore_form(string $skill_id): void
{ ?>
    <form class="openmira-admin-inline-form" method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills&skill_status=trashed'))
    ; ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="restore">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button-link openmira-admin-action-link"><?php esc_html_e(
            'Restore',
            domain: 'open-mira',
        ); ?></button>
    </form>
    <?php }

/**
 * Render permanent delete form.
 */
function openmira_render_skill_delete_form(string $skill_id, bool $permanent = false): void
{ ?>
    <form class="openmira-admin-inline-form" method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills' . ($permanent ? '&skill_status=trashed' : '')))
    ; ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="delete">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button-link-delete openmira-admin-action-link" onclick="return confirm('<?php echo
            esc_js(__('Permanently delete this user skill?', domain: 'open-mira'))
        ; ?>');"><?php esc_html_e('Permanently delete', domain: 'open-mira'); ?></button>
    </form>
    <?php }

/**
 * Render customize form.
 */
function openmira_render_skill_customize_form(string $skill_id): void
{ ?>
    <form class="openmira-admin-inline-form" method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills'))
    ; ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="customize">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button-link openmira-admin-action-link"><?php esc_html_e(
            'Customize',
            domain: 'open-mira',
        ); ?></button>
    </form>
    <?php }

/**
 * Render prompt enable toggle form.
 */
function openmira_render_skill_prompt_toggle_form(string $skill_id, bool $enabled): void
{ ?>
    <form class="openmira-admin-inline-form" method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills'))
    ; ?>">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="toggle_prompt">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <input type="hidden" name="enable_prompt" value="<?php echo esc_attr($enabled ? '0' : '1'); ?>">
        <button type="submit" class="button-link openmira-admin-action-link">
            <?php echo esc_html($enabled ? __('Enabled', domain: 'open-mira') : __('Disabled', domain: 'open-mira')); ?>
        </button>
    </form>
    <?php }

/**
 * Render native WordPress revisions link for a CPT skill.
 */
function openmira_render_skill_revisions_link(int $post_id): void
{
    if ($post_id <= 0) {
        ?><span class="openmira-admin-muted"><?php esc_html_e('Revisions', domain: 'open-mira'); ?></span><?php

        return;
    }

    $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => 1]);
    $revision = reset($revisions);
    if (!$revision instanceof WP_Post) {
        ?><span class="openmira-admin-muted"><?php esc_html_e('Revisions', domain: 'open-mira'); ?></span><?php

        return;
    }

    ?>
    <a class="openmira-admin-action-link" href="<?php echo
        esc_url(admin_url('revision.php?revision=' . (int) $revision->ID))
    ; ?>"><?php esc_html_e('Revisions', domain: 'open-mira'); ?></a>
    <?php
}

/**
 * Return source label for a skill.
 */
function openmira_skill_source_label(string $source, bool $overrides): string
{
    if ($overrides) {
        return __('Custom (overrides built-in)', domain: 'open-mira');
    }
    if ($source === 'cpt') {
        return __('CPT', domain: 'open-mira');
    }

    return __('Filesystem', domain: 'open-mira');
}

/**
 * Return trashed CPT skills as canonical skill rows.
 *
 * @return array<string, array<string, mixed>>
 */
function openmira_get_trashed_skill_rows(): array
{
    if (!function_exists('openmira_get_cpt_skill_posts') || !function_exists('openmira_skill_from_cpt_post')) {
        return [];
    }

    $skills = [];
    foreach (openmira_get_cpt_skill_posts(['trash']) as $post) {
        $skill = openmira_skill_from_cpt_post($post);
        if (!is_array($skill)) {
            continue;
        }
        $skills[(string) $skill['id']] = $skill;
    }
    ksort($skills);

    return $skills;
}
