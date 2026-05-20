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
                'Skills are MCP Prompts loaded from <code>includes/skills/&lt;id&gt;/SKILL.md</code> and <code>wp-content/openmira-skills/&lt;id&gt;/SKILL.md</code>. Built-in skills are read-only; customize one to copy it into user content for editing.',
            )
        ; ?></p>
        <?php openmira_render_skill_notice(); ?>
        <?php

        if ($action === 'add') {
            openmira_render_skill_edit_form(null);
            echo '</div>';
            return;
        }
        if ($action === 'edit') {
            $skill = $skills[$skill_id] ?? null;
            if (is_array($skill) && $skill['source'] === 'user') {
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
        $result === 'deleted' => __('Skill deleted.', domain: 'open-mira'),
        $result === 'customized' => __(
            'Built-in skill copied to user content. You can edit it now.',
            domain: 'open-mira',
        ),
        str_starts_with($result, 'imported-') => sprintf(
            /* translators: %s is an import result summary. */
            __('Skills import complete: %s.', domain: 'open-mira'),
            $result,
        ),
        default => __('Skill action complete.', domain: 'open-mira'),
    };
    ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
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
    $user_skills = array_filter($skills, static fn(array $skill): bool => $skill['source'] === 'user');
    $built_in_skills = array_filter($skills, static fn(array $skill): bool => $skill['source'] === 'built-in');
    ?>
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
    <?php openmira_render_skill_group(__('User Skills', domain: 'open-mira'), $user_skills, true); ?>
    <?php openmira_render_skill_group(__('Built-in Skills', domain: 'open-mira'), $built_in_skills, false); ?>
    <?php
}

/**
 * Render the import form.
 */
function openmira_render_skill_import_panel(): void
{ ?>
    <div id="openmira-skill-import-panel" class="card" style="max-width: 760px; margin: 0 0 16px;" hidden>
        <h2><?php esc_html_e('Import Skills', domain: 'open-mira'); ?></h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo
            esc_url(admin_url('admin.php?page=openmira-skills'))
        ; ?>">
            <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
            <input type="hidden" name="openmira_skill_action" value="import">
            <p>
                <label for="openmira-skill-import-file"><strong><?php esc_html_e(
                    'File',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <input id="openmira-skill-import-file" type="file" name="skill_import_file" accept=".md,.markdown,.zip" required>
            </p>
            <p>
                <label for="openmira-single-skill-id"><strong><?php esc_html_e(
                    'Skill ID for single SKILL.md imports',
                    domain: 'open-mira',
                ); ?></strong></label><br>
                <input id="openmira-single-skill-id" class="regular-text" name="single_skill_id" pattern="^[a-z0-9][a-z0-9._-]{0,79}$">
            </p>
            <p>
                <label><input type="checkbox" name="skip_existing" value="1"> <?php esc_html_e(
                    'Skip existing user skills',
                    domain: 'open-mira',
                ); ?></label>
            </p>
            <?php submit_button(__('Import Skills', domain: 'open-mira'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php }

/**
 * Render a skill group.
 *
 * @param array<string, array<string, mixed>> $skills
 */
function openmira_render_skill_group(string $title, array $skills, bool $is_user_group): void
{ ?>
    <h2><?php echo esc_html($title); ?></h2>
    <?php if ($skills === []): ?>
        <p><?php echo
            esc_html(
                $is_user_group
                    ? __('No user skills yet.', domain: 'open-mira')
                    : __('No built-in skills found.', domain: 'open-mira'),
            )
        ; ?></p>
    <?php else: ?>
        <?php foreach ($skills as $skill): ?>
            <?php openmira_render_skill_card($skill); ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php }

/**
 * Render one skill card.
 *
 * @param array<string, mixed> $skill
 */
function openmira_render_skill_card(array $skill): void
{
    $skill_id = (string) $skill['id'];
    $source = (string) $skill['source'];
    $overrides = ($skill['overrides_built_in'] ?? false) === true;
    ?>
    <section class="card" style="max-width: 960px; margin-top: 16px;">
        <h3 style="margin-top:0;"><?php echo esc_html((string) $skill['title']); ?></h3>
        <p>
            <span class="openmira-admin-source-badge openmira-admin-source-badge--<?php echo
                esc_attr($overrides ? 'override' : $source)
            ; ?>">
                <?php echo esc_html(openmira_skill_source_label($source, $overrides)); ?>
            </span>
            <strong><?php esc_html_e('ID:', domain: 'open-mira'); ?></strong> <code><?php echo
                esc_html($skill_id)
            ; ?></code>
            <strong><?php esc_html_e('Prompt:', domain: 'open-mira'); ?></strong> <code><?php echo
                esc_html((string) $skill['prompt_name'])
            ; ?></code>
        </p>
        <p><?php echo esc_html((string) $skill['description']); ?></p>
        <p class="openmira-admin-card-actions">
            <?php if ($source === 'user'): ?>
                <a class="openmira-admin-action-link" href="<?php echo
                    esc_url(add_query_arg([
                        'page' => 'openmira-skills',
                        'skill_action' => 'edit',
                        'skill_id' => $skill_id,
                    ], admin_url('admin.php')))
                ; ?>"><?php esc_html_e('Edit', domain: 'open-mira'); ?></a>
                <a class="openmira-admin-action-link" href="<?php echo
                    esc_url(wp_nonce_url(
                        admin_url(
                            'admin.php?page=openmira-skills&openmira_skill_export=1&skill_id='
                                . rawurlencode($skill_id),
                        ),
                        action: 'openmira_skill_export',
                    ))
                ; ?>"><?php esc_html_e('Export', domain: 'open-mira'); ?></a>
                <?php openmira_render_skill_delete_form($skill_id); ?>
            <?php endif; ?>
            <?php if ($source === 'built-in'): ?>
                <?php openmira_render_skill_customize_form($skill_id); ?>
            <?php endif; ?>
            <button type="button" class="button button-small" onclick="this.closest('section').querySelector('details').open = !this.closest('section').querySelector('details').open;"><?php esc_html_e(
                'Preview',
                domain: 'open-mira',
            ); ?></button>
        </p>
        <details>
            <summary><?php esc_html_e('Preview SKILL.md', domain: 'open-mira'); ?></summary>
            <pre style="white-space: pre-wrap; overflow: auto; max-height: 520px; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde;"><?php echo
                esc_html((string) $skill['body'])
            ; ?></pre>
        </details>
    </section>
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
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="openmira-skill-id"><?php esc_html_e(
                    'Skill ID',
                    domain: 'open-mira',
                ); ?></label></th>
                <td>
                    <input id="openmira-skill-id" class="regular-text" name="skill_id" pattern="^[a-z0-9][a-z0-9._-]{0,79}$" required value="<?php echo
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
            <tr>
                <th scope="row"><label for="openmira-skill-description"><?php esc_html_e(
                    'Description',
                    domain: 'open-mira',
                ); ?></label></th>
                <td><input id="openmira-skill-description" class="large-text" name="skill_description" required value="<?php echo
                    esc_attr($description)
                ; ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="openmira-skill-body"><?php esc_html_e(
                    'Body',
                    domain: 'open-mira',
                ); ?></label></th>
                <td>
                    <textarea id="openmira-skill-body" class="large-text code" rows="18" name="skill_body" required><?php echo
                        esc_textarea($body)
                    ; ?></textarea>
                    <p class="description"><?php esc_html_e(
                        'Markdown prompt body. Maximum 64 KB.',
                        domain: 'open-mira',
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(
            $editing ? __('Update Skill', domain: 'open-mira') : __('Create Skill', domain: 'open-mira'),
        ); ?>
    </form>
    <?php
}

/**
 * Render delete form.
 */
function openmira_render_skill_delete_form(string $skill_id): void
{ ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills'))
    ; ?>" style="display:inline;">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="delete">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button-link-delete openmira-admin-action-link" onclick="return confirm('<?php echo
            esc_js(__('Delete this user skill?', domain: 'open-mira'))
        ; ?>');"><?php esc_html_e('Delete', domain: 'open-mira'); ?></button>
    </form>
    <?php }

/**
 * Render customize form.
 */
function openmira_render_skill_customize_form(string $skill_id): void
{ ?>
    <form method="post" action="<?php echo
        esc_url(admin_url('admin.php?page=openmira-skills'))
    ; ?>" style="display:inline;">
        <?php wp_nonce_field(action: 'openmira_skill_action', name: '_openmira_skill_nonce'); ?>
        <input type="hidden" name="openmira_skill_action" value="customize">
        <input type="hidden" name="skill_id" value="<?php echo esc_attr($skill_id); ?>">
        <button type="submit" class="button button-small"><?php esc_html_e(
            'Customize',
            domain: 'open-mira',
        ); ?></button>
    </form>
    <?php }

/**
 * Return source label for a skill.
 */
function openmira_skill_source_label(string $source, bool $overrides): string
{
    if ($overrides) {
        return __('Custom (overrides built-in)', domain: 'open-mira');
    }
    if ($source === 'user') {
        return __('User', domain: 'open-mira');
    }

    return __('Built-in', domain: 'open-mira');
}
