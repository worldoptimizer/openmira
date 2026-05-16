<?php

declare(strict_types=1);

/**
 * Abilities: safe WordPress theme actions.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/activate-theme', [
    'label' => __('Activate Theme', domain: 'open-mira'),
    'description' => __(
        'Activates an installed WordPress theme by slug without requiring WP-CLI.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'Theme stylesheet slug to activate.',
                'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$',
            ],
            'stylesheet' => [
                'type' => 'string',
                'description' => 'Alias for slug.',
                'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$',
            ],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'previous' => ['type' => 'string'],
            'current' => ['type' => 'string'],
            'theme' => ['type' => 'object'],
            'audit' => ['type' => 'object'],
        ],
        'required' => ['previous', 'current', 'theme', 'audit'],
    ],
    'execute_callback' => 'openmira_activate_theme',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use after scaffold-theme when the generated theme should become active. Prefer scaffold-theme activate=true when creating and activating in one step.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Activate an installed theme.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_activate_theme(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/activate-theme');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $slug = sanitize_key((string) ($input['slug'] ?? $input['stylesheet'] ?? ''));
    if ($slug === '') {
        return new WP_Error('missing_theme_slug', 'Provide slug or stylesheet for the theme to activate.');
    }

    $theme = wp_get_theme($slug);
    if (!$theme->exists()) {
        return new WP_Error('theme_not_found', sprintf('Theme not found: %s', $slug));
    }

    $previous = get_stylesheet();
    switch_theme($slug);
    $current = get_stylesheet();
    if ($current !== $slug) {
        return new WP_Error('theme_activation_failed', sprintf('Theme activation failed: %s', $slug));
    }

    $audit = openmira_record_audit_event([
        'ability' => 'openmira/activate-theme',
        'operation' => 'activate-theme',
        'target_path' => 'wp-content/themes/' . $slug,
        'status' => 'success',
        'diff_summary' => sprintf('Active theme changed from %s to %s.', $previous, $current),
    ]);

    return [
        'previous' => $previous,
        'current' => $current,
        'theme' => [
            'slug' => $current,
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'is_block_theme' => wp_is_block_theme(),
        ],
        'audit' => $audit,
    ];
}
