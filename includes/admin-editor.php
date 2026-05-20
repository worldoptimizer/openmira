<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Shared admin editor helpers.
 */

if (!defined('ABSPATH')) {
    exit();
}

function openmira_enqueue_markdown_editor(string $textarea_id): void
{
    if (!function_exists('wp_enqueue_code_editor')) {
        return;
    }
    $settings = wp_enqueue_code_editor([
        'type' => 'text/markdown',
        'codemirror' => [
            'lineNumbers' => true,
            'lineWrapping' => true,
            'indentUnit' => 2,
        ],
    ]);
    if (!is_array($settings)) {
        return;
    }
    wp_add_inline_script('code-editor', sprintf(
        'jQuery(function($){ if (window.wp && wp.codeEditor) { wp.codeEditor.initialize($(%s), %s); } });',
        wp_json_encode('#' . $textarea_id),
        wp_json_encode($settings),
    ));
}
