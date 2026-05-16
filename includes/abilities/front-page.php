<?php

declare(strict_types=1);

/**
 * Ability: Set WordPress static front page safely.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/set-front-page', [
    'label' => __('Set Front Page', domain: 'open-mira'),
    'description' => __(
        'Sets a published page as the site front page without exposing generic wp option update.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Page ID to use as the static front page.',
                'minimum' => 1,
            ],
            'page_id' => [
                'type' => 'integer',
                'description' => 'Alias for post_id.',
                'minimum' => 1,
            ],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'front_page' => ['type' => 'object'],
            'options' => ['type' => 'object'],
            'audit' => ['type' => 'object'],
        ],
        'required' => ['front_page', 'options', 'audit'],
    ],
    'execute_callback' => 'openmira_set_front_page',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use after creating a landing page instead of wp option update show_on_front/page_on_front.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Set a page as the site front page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_set_front_page(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/set-front-page');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $post_id = (int) ($input['post_id'] ?? $input['page_id'] ?? 0);
    if ($post_id < 1) {
        return new WP_Error('missing_post_id', 'Provide post_id or page_id for the page to use as the front page.');
    }

    /** @var WP_Post|null $post */
    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return new WP_Error('invalid_front_page', 'The requested front page must be an existing page.');
    }
    if (!in_array($post->post_status, ['publish', 'private'], strict: true)) {
        return new WP_Error('front_page_not_public', 'Publish the page before setting it as the static front page.');
    }

    $previous = [
        'show_on_front' => (string) get_option('show_on_front'),
        'page_on_front' => (int) get_option('page_on_front'),
    ];

    update_option(option: 'show_on_front', value: 'page');
    update_option(option: 'page_on_front', value: $post_id);

    $audit = openmira_record_audit_event([
        'ability' => 'openmira/set-front-page',
        'operation' => 'update-option',
        'target_path' => 'wp_options:show_on_front,page_on_front',
        'status' => 'success',
        'diff_summary' => sprintf(
            'Front page changed from %s/%d to page/%d.',
            $previous['show_on_front'],
            $previous['page_on_front'],
            $post_id,
        ),
    ]);

    return [
        'front_page' => [
            'id' => $post_id,
            'title' => get_the_title($post_id),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'link' => get_permalink($post_id),
        ],
        'options' => [
            'previous' => $previous,
            'current' => [
                'show_on_front' => (string) get_option('show_on_front'),
                'page_on_front' => (int) get_option('page_on_front'),
            ],
        ],
        'audit' => $audit,
    ];
}
