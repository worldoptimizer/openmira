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

/**
 * Render the Skills admin page.
 */
function openmira_render_skills_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage Open Mira Skills.', domain: 'open-mira'));
    }

    $skills = openmira_get_skills();
    ?>
    <div class="wrap openmira-skills-page">
        <h1><?php esc_html_e('Open Mira Skills', domain: 'open-mira'); ?></h1>
        <p>
            <?php

            echo
                wp_kses_post(
                    'Skills are MCP Prompts loaded from <code>includes/skills/&lt;id&gt;/SKILL.md</code>. Agents connected via MCP can list them via <code>prompts/list</code> and invoke them via <code>prompts/get</code>. To add or modify a skill, edit the SKILL.md file directly.',
                )
            ;
            ?>
        </p>

        <?php if ($skills === []): ?>
            <p><?php esc_html_e('No skills are installed.', domain: 'open-mira'); ?></p>
        <?php endif; ?>

        <?php foreach ($skills as $skill): ?>
            <section class="card" style="max-width: 960px; margin-top: 16px;">
                <h2><?php echo esc_html($skill['title']); ?></h2>
                <p><strong><?php esc_html_e('ID:', domain: 'open-mira'); ?></strong> <code><?php echo
                    esc_html($skill['id'])
                ; ?></code></p>
                <p><strong><?php esc_html_e('Prompt:', domain: 'open-mira'); ?></strong> <code><?php echo
                    esc_html($skill['prompt_name'])
                ; ?></code></p>
                <p><?php echo esc_html($skill['description']); ?></p>
                <details>
                    <summary><?php esc_html_e('Preview SKILL.md', domain: 'open-mira'); ?></summary>
                    <pre style="white-space: pre-wrap; overflow: auto; max-height: 520px; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde;"><?php echo
                        esc_html($skill['body'])
                    ; ?></pre>
                </details>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
}
