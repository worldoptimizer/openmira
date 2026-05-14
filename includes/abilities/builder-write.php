<?php

declare(strict_types=1);

/**
 * Abilities: Safe builder backups and guarded Gutenberg writes.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_BUILDER_BACKUPS_META_KEY = '_novamira_builder_backups';

const NOVAMIRA_BUILDER_BACKUPS_MAX = 25;

wp_register_ability('novamira/backup-builder-content', [
    'label' => __('Backup Builder Content', domain: 'novamira'),
    'description' => __(
        'Creates a post-local backup of Gutenberg post_content and Bricks Builder meta data before builder mutations. Use this before write-gutenberg-content or any future Bricks write.',
        domain: 'novamira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post/template ID to back up.', 'minimum' => 1],
            'note' => ['type' => 'string', 'description' => 'Optional reason for the backup.', 'maxLength' => 500],
            'include_post_content' => [
                'type' => 'boolean',
                'description' => 'Whether to include raw post_content.',
                'default' => true,
            ],
            'include_bricks_data' => [
                'type' => 'boolean',
                'description' => 'Whether to include known Bricks meta areas.',
                'default' => true,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'backup' => ['type' => 'object'],
            'backup_count' => ['type' => 'integer'],
        ],
        'required' => ['backup', 'backup_count'],
    ],
    'execute_callback' => 'novamira_backup_builder_content',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Creates a local post meta backup. Call this before builder write operations.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('novamira/list-builder-backups', [
    'label' => __('List Builder Backups', domain: 'novamira'),
    'description' => __('Lists Open Mira builder backups stored on a post/template.', domain: 'novamira'),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post/template ID.', 'minimum' => 1],
            'include_payload' => [
                'type' => 'boolean',
                'description' => 'Whether to include full backup payloads.',
                'default' => false,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'backups' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['post', 'backups', 'count'],
    ],
    'execute_callback' => 'novamira_list_builder_backups',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('novamira/write-gutenberg-content', [
    'label' => __('Write Gutenberg Content', domain: 'novamira'),
    'description' => __(
        'Safely replaces one post post_content value with guarded Gutenberg content. Supports optimistic hash checks and automatic backups. Does not edit Bricks meta.',
        domain: 'novamira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post ID to update.', 'minimum' => 1],
            'content' => ['type' => 'string', 'description' => 'Complete replacement post_content.'],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash of the current post_content. If supplied and stale, the write is rejected.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'create_backup' => [
                'type' => 'boolean',
                'description' => 'Whether to create a backup before writing.',
                'default' => true,
            ],
            'backup_note' => [
                'type' => 'string',
                'description' => 'Optional backup note.',
                'maxLength' => 500,
            ],
            'allow_classic_content' => [
                'type' => 'boolean',
                'description' => 'Allow writing content that does not contain Gutenberg block comments.',
                'default' => false,
            ],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'backup' => ['type' => 'object'],
            'previous_hash' => ['type' => 'string'],
            'new_hash' => ['type' => 'string'],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['post', 'previous_hash', 'new_hash', 'block_count', 'blocks'],
    ],
    'execute_callback' => 'novamira_write_gutenberg_content',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Supply expected_current_hash from read-gutenberg-content when possible. This writes the complete post_content, not a patch.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('novamira/restore-builder-backup', [
    'label' => __('Restore Builder Backup', domain: 'novamira'),
    'description' => __(
        'Restores a stored Open Mira builder backup. By default this restores post_content only and creates a new pre-restore backup. Bricks meta restore must be explicitly enabled.',
        domain: 'novamira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post/template ID.', 'minimum' => 1],
            'backup_id' => [
                'type' => 'string',
                'description' => 'Backup ID from list-builder-backups.',
                'minLength' => 1,
            ],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash of current post_content. If supplied and stale, restore is rejected.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'restore_post_content' => [
                'type' => 'boolean',
                'description' => 'Whether to restore the backed-up post_content.',
                'default' => true,
            ],
            'restore_bricks_data' => [
                'type' => 'boolean',
                'description' => 'Whether to restore backed-up Bricks meta areas. Explicit opt-in because it mutates builder meta.',
                'default' => false,
            ],
            'create_backup' => [
                'type' => 'boolean',
                'description' => 'Whether to create a pre-restore backup.',
                'default' => true,
            ],
        ],
        'required' => ['post_id', 'backup_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'restored' => ['type' => 'object'],
            'previous_hash' => ['type' => 'string'],
            'new_hash' => ['type' => 'string'],
            'pre_restore_backup' => ['type' => 'object'],
        ],
        'required' => ['post', 'restored', 'previous_hash', 'new_hash'],
    ],
    'execute_callback' => 'novamira_restore_builder_backup',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer restoring post_content first. Restore Bricks meta only when the backup and target post are known to match.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('novamira/patch-gutenberg-blocks', [
    'label' => __('Patch Gutenberg Blocks', domain: 'novamira'),
    'description' => __(
        'Applies a focused Gutenberg block operation to post_content, then serializes with serialize_blocks. Supports append, prepend, replace, remove, and update-attrs with hash checks and automatic backups.',
        domain: 'novamira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post ID to update.', 'minimum' => 1],
            'operation' => [
                'type' => 'string',
                'description' => 'Patch operation.',
                'enum' => ['append', 'prepend', 'replace', 'remove', 'update-attrs'],
            ],
            'block_path' => [
                'type' => 'array',
                'description' => 'Zero-based path to target block. Empty path means root for append/prepend.',
                'items' => ['type' => 'integer', 'minimum' => 0],
                'default' => [],
            ],
            'block_markup' => [
                'type' => 'string',
                'description' => 'Serialized block markup for append, prepend, or replace. Must parse to exactly one block.',
            ],
            'attrs' => [
                'type' => 'object',
                'description' => 'Attributes for update-attrs.',
            ],
            'attrs_mode' => [
                'type' => 'string',
                'description' => 'Whether attrs replace or merge with current attrs.',
                'enum' => ['merge', 'replace'],
                'default' => 'merge',
            ],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash of current post_content. If supplied and stale, patch is rejected.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'create_backup' => [
                'type' => 'boolean',
                'description' => 'Whether to create a backup before patching.',
                'default' => true,
            ],
            'backup_note' => ['type' => 'string', 'description' => 'Optional backup note.', 'maxLength' => 500],
        ],
        'required' => ['post_id', 'operation'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'operation' => ['type' => 'string'],
            'previous_hash' => ['type' => 'string'],
            'new_hash' => ['type' => 'string'],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'backup' => ['type' => 'object'],
        ],
        'required' => ['post', 'operation', 'previous_hash', 'new_hash', 'block_count', 'blocks'],
    ],
    'execute_callback' => 'novamira_patch_gutenberg_blocks',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use read-gutenberg-content first, pass expected_current_hash, and patch a precise block path instead of replacing full post_content.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Create a builder backup.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function novamira_backup_builder_content(array $input): array|WP_Error
{
    $post = novamira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $backup = novamira_create_builder_backup_entry(
        $post,
        (string) ($input['note'] ?? ''),
        novamira_falsey_input($input['include_post_content'] ?? true) === false,
        novamira_falsey_input($input['include_bricks_data'] ?? true) === false,
    );
    $backups = novamira_get_builder_backups($post->ID);
    array_unshift($backups, $backup);
    $backups = array_slice($backups, offset: 0, length: NOVAMIRA_BUILDER_BACKUPS_MAX);
    novamira_update_builder_backups($post->ID, $backups);

    return [
        'backup' => novamira_summarize_builder_backup($backup, include_payload: true),
        'backup_count' => count($backups),
    ];
}

/**
 * List builder backups for a post.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function novamira_list_builder_backups(array $input): array|WP_Error
{
    $post = novamira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $include_payload = novamira_truthy_input($input['include_payload'] ?? false);
    $backups = array_map(static fn(array $backup): array => novamira_summarize_builder_backup(
        $backup,
        $include_payload,
    ), novamira_get_builder_backups($post->ID));

    return [
        'post' => novamira_build_post_inventory_item($post),
        'backups' => $backups,
        'count' => count($backups),
    ];
}

/**
 * Restore a stored builder backup.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function novamira_restore_builder_backup(array $input): array|WP_Error
{
    $post = novamira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $backup = novamira_find_builder_backup($post->ID, (string) ($input['backup_id'] ?? ''));
    if (is_wp_error($backup)) {
        return $backup;
    }

    $previous_hash = novamira_hash_content($post->post_content);
    $expected_hash = (string) ($input['expected_current_hash'] ?? '');
    if ($expected_hash !== '' && $expected_hash !== $previous_hash) {
        return new WP_Error('stale_post_content', 'Current post_content hash does not match expected_current_hash.', [
            'current_hash' => $previous_hash,
        ]);
    }

    $pre_restore_backup = null;
    if (!novamira_falsey_input($input['create_backup'] ?? true)) {
        $backup_result = novamira_backup_builder_content([
            'post_id' => $post->ID,
            'note' => 'Automatic backup before restoring backup ' . (string) ($backup['id'] ?? ''),
            'include_post_content' => true,
            'include_bricks_data' => true,
        ]);
        if (is_wp_error($backup_result)) {
            return $backup_result;
        }
        if (is_array($backup_result['backup'] ?? null)) {
            $pre_restore_backup = $backup_result['backup'];
        }
    }

    $payload = is_array($backup['payload'] ?? null) ? $backup['payload'] : [];
    $restored = [
        'post_content' => false,
        'bricks_data' => [],
    ];

    if (!novamira_falsey_input($input['restore_post_content'] ?? true)) {
        if (!is_string($payload['post_content'] ?? null)) {
            return new WP_Error('backup_missing_post_content', 'The selected backup does not contain post_content.');
        }
        $update_result = novamira_update_post_content($post->ID, $payload['post_content']);
        if (is_wp_error($update_result)) {
            return $update_result;
        }
        $restored['post_content'] = true;
    }

    if (novamira_truthy_input($input['restore_bricks_data'] ?? false)) {
        $bricks_result = novamira_restore_bricks_backup_payload($post->ID, $payload);
        if (is_wp_error($bricks_result)) {
            return $bricks_result;
        }
        $restored['bricks_data'] = $bricks_result;
    }

    $updated_post = novamira_get_post_or_error($post->ID);
    if (is_wp_error($updated_post)) {
        return $updated_post;
    }

    $response = [
        'post' => novamira_build_post_inventory_item($updated_post),
        'restored' => $restored,
        'previous_hash' => $previous_hash,
        'new_hash' => novamira_hash_content($updated_post->post_content),
    ];
    if (is_array($pre_restore_backup)) {
        $response['pre_restore_backup'] = $pre_restore_backup;
    }

    return $response;
}

/**
 * Patch Gutenberg blocks with serialize_blocks output.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function novamira_patch_gutenberg_blocks(array $input): array|WP_Error
{
    $post = novamira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $operation = (string) ($input['operation'] ?? '');
    $previous_hash = novamira_hash_content($post->post_content);
    $expected_hash = (string) ($input['expected_current_hash'] ?? '');
    if ($expected_hash !== '' && $expected_hash !== $previous_hash) {
        return new WP_Error('stale_post_content', 'Current post_content hash does not match expected_current_hash.', [
            'current_hash' => $previous_hash,
        ]);
    }

    $path = novamira_normalize_block_path($input['block_path'] ?? []);
    if (is_wp_error($path)) {
        return $path;
    }

    $params = novamira_build_block_patch_params($operation, $input);
    if (is_wp_error($params)) {
        return $params;
    }

    $patched_blocks = novamira_apply_block_patch(parse_blocks($post->post_content), $path, $operation, $params);
    if (is_wp_error($patched_blocks)) {
        return $patched_blocks;
    }

    // @mago-expect analysis:less-specific-nested-argument-type
    $content = serialize_blocks($patched_blocks);
    $write_result = novamira_write_gutenberg_content([
        'post_id' => $post->ID,
        'content' => $content,
        'expected_current_hash' => $previous_hash,
        'create_backup' => !novamira_falsey_input($input['create_backup'] ?? true),
        'backup_note' => (string) ($input['backup_note'] ?? 'Automatic backup before Gutenberg block patch'),
    ]);
    if (is_wp_error($write_result)) {
        return $write_result;
    }

    $write_result['operation'] = $operation;
    return $write_result;
}

/**
 * Replace Gutenberg post content with safeguards.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
function novamira_write_gutenberg_content(array $input): array|WP_Error
{
    $post = novamira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $content = (string) ($input['content'] ?? '');
    $previous_hash = novamira_hash_content($post->post_content);
    $expected_hash = (string) ($input['expected_current_hash'] ?? '');
    if ($expected_hash !== '' && $expected_hash !== $previous_hash) {
        return new WP_Error('stale_post_content', 'Current post_content hash does not match expected_current_hash.', [
            'current_hash' => $previous_hash,
        ]);
    }

    $has_blocks = has_blocks($content);
    if (!$has_blocks && !novamira_truthy_input($input['allow_classic_content'] ?? false)) {
        return new WP_Error(
            'content_has_no_blocks',
            'Replacement content does not contain Gutenberg block markup. Set allow_classic_content=true to write classic HTML content.',
        );
    }

    $parsed_blocks = parse_blocks($content);
    $backup = null;
    if (!novamira_falsey_input($input['create_backup'] ?? true)) {
        $backup_result = novamira_backup_builder_content([
            'post_id' => $post->ID,
            'note' => (string) ($input['backup_note'] ?? 'Automatic backup before Gutenberg write'),
            'include_post_content' => true,
            'include_bricks_data' => true,
        ]);
        if (is_wp_error($backup_result)) {
            return $backup_result;
        }
        if (is_array($backup_result['backup'] ?? null)) {
            $backup = $backup_result['backup'];
        }
    }

    $update_result = wp_update_post([
        'ID' => $post->ID,
        'post_content' => wp_slash($content),
    ], wp_error: true);
    if (is_wp_error($update_result)) {
        return $update_result;
    }

    $updated_post = novamira_get_post_or_error($post->ID);
    if (is_wp_error($updated_post)) {
        return $updated_post;
    }

    $response = [
        'post' => novamira_build_post_inventory_item($updated_post),
        'previous_hash' => $previous_hash,
        'new_hash' => novamira_hash_content($content),
        'block_count' => count($parsed_blocks),
        'blocks' => novamira_summarize_blocks($parsed_blocks),
    ];
    if (is_array($backup)) {
        $response['backup'] = $backup;
    }

    return $response;
}

/**
 * Update post_content.
 */
function novamira_update_post_content(int $post_id, string $content): int|WP_Error
{
    return wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($content),
    ], wp_error: true);
}

/**
 * Find a stored backup by ID.
 *
 * @return array<array-key, mixed>|WP_Error
 */
function novamira_find_builder_backup(int $post_id, string $backup_id): array|WP_Error
{
    if ($backup_id === '') {
        return new WP_Error('missing_backup_id', 'backup_id is required.');
    }

    foreach (novamira_get_builder_backups($post_id) as $backup) {
        if ((string) ($backup['id'] ?? '') === $backup_id) {
            return $backup;
        }
    }

    return new WP_Error('backup_not_found', 'Backup not found.');
}

/**
 * Restore Bricks meta values from a backup payload.
 *
 * @param array<array-key, mixed> $payload
 * @return array<string, bool>|WP_Error
 */
function novamira_restore_bricks_backup_payload(int $post_id, array $payload): array|WP_Error
{
    $bricks = is_array($payload['bricks'] ?? null) ? $payload['bricks'] : null;
    if ($bricks === null) {
        return new WP_Error('backup_missing_bricks_data', 'The selected backup does not contain Bricks data.');
    }

    $restored = [];
    $allowed_meta_keys = novamira_get_bricks_meta_keys();
    // @mago-expect analysis:mixed-assignment
    foreach ($bricks as $area => $data) {
        if (!is_string($area) || !is_array($data) || !array_key_exists($area, $allowed_meta_keys)) {
            continue;
        }
        $meta_key = (string) ($data['meta_key'] ?? '');
        if ($meta_key !== $allowed_meta_keys[$area]) {
            return new WP_Error(
                'invalid_bricks_backup_meta_key',
                'Backup Bricks meta key does not match the expected area key.',
            );
        }
        $value = is_array($data['value'] ?? null) ? $data['value'] : [];
        update_post_meta($post_id, $meta_key, $value);
        $restored[$area] = true;
    }

    return $restored;
}

/**
 * Normalize a block path from ability input.
 *
 * @return list<int>|WP_Error
 */
function novamira_normalize_block_path(mixed $value): array|WP_Error
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        return new WP_Error('invalid_block_path', 'block_path must be an array of zero-based integers.');
    }

    $path = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($value as $part) {
        $index = (int) $part;
        if ((string) $part !== (string) $index || $index < 0) {
            return new WP_Error('invalid_block_path', 'block_path must contain only zero-based integers.');
        }
        $path[] = $index;
    }

    return $path;
}

/**
 * Build operation params for a block patch.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function novamira_build_block_patch_params(string $operation, array $input): array|WP_Error
{
    if (in_array($operation, ['append', 'prepend', 'replace'], strict: true)) {
        $block = novamira_parse_single_block_markup((string) ($input['block_markup'] ?? ''));
        if (is_wp_error($block)) {
            return $block;
        }
        return ['block' => $block];
    }

    if ($operation === 'update-attrs') {
        if (!is_array($input['attrs'] ?? null)) {
            return new WP_Error('missing_attrs', 'attrs object is required for update-attrs.');
        }
        return [
            'attrs' => $input['attrs'],
            'attrs_mode' => (string) ($input['attrs_mode'] ?? 'merge'),
        ];
    }

    if ($operation === 'remove') {
        return [];
    }

    return new WP_Error('invalid_operation', 'Unsupported Gutenberg patch operation.');
}

/**
 * Parse serialized markup into exactly one meaningful block.
 *
 * @return array<string, mixed>|WP_Error
 */
function novamira_parse_single_block_markup(string $markup): array|WP_Error
{
    if (trim($markup) === '') {
        return new WP_Error('missing_block_markup', 'block_markup is required for this operation.');
    }

    $blocks = [];
    foreach (parse_blocks($markup) as $block) {
        $block_name = $block['blockName'] ?? null;
        $inner_html = trim($block['innerHTML']);
        if ($block_name === null && $inner_html === '') {
            continue;
        }
        $blocks[] = $block;
    }

    if (count($blocks) !== 1) {
        return new WP_Error('invalid_block_markup', 'block_markup must parse to exactly one meaningful block.');
    }

    return $blocks[0];
}

/**
 * Apply a Gutenberg block patch to parsed blocks.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $path
 * @param array<string, mixed>   $params
 * @return array<array-key, mixed>|WP_Error
 */
function novamira_apply_block_patch(array $blocks, array $path, string $operation, array $params): array|WP_Error
{
    if ($path === []) {
        if ($operation === 'append') {
            $blocks[] = $params['block'];
            return $blocks;
        }
        if ($operation === 'prepend') {
            array_unshift($blocks, $params['block']);
            return $blocks;
        }
        return new WP_Error('missing_block_path', 'block_path is required for this operation.');
    }

    $index = array_shift($path);
    if (!array_key_exists($index, $blocks) || !is_array($blocks[$index])) {
        return new WP_Error('block_path_not_found', 'The requested block_path does not exist.');
    }

    if ($path === []) {
        return novamira_apply_block_patch_at_target($blocks, $index, $operation, $params);
    }

    $inner_blocks = is_array($blocks[$index]['innerBlocks'] ?? null) ? $blocks[$index]['innerBlocks'] : [];
    $patched_inner_blocks = novamira_apply_block_patch($inner_blocks, $path, $operation, $params);
    if (is_wp_error($patched_inner_blocks)) {
        return $patched_inner_blocks;
    }
    // @mago-expect analysis:mixed-array-assignment
    $blocks[$index]['innerBlocks'] = $patched_inner_blocks;

    return $blocks;
}

/**
 * Apply a block patch at the final target index.
 *
 * @param array<array-key, mixed> $blocks
 * @param array<string, mixed>   $params
 * @return array<array-key, mixed>|WP_Error
 */
function novamira_apply_block_patch_at_target(
    array $blocks,
    int $index,
    string $operation,
    array $params,
): array|WP_Error {
    if ($operation === 'replace') {
        $blocks[$index] = $params['block'];
        return $blocks;
    }
    if ($operation === 'remove') {
        unset($blocks[$index]);
        return array_values($blocks);
    }
    if ($operation === 'append' || $operation === 'prepend') {
        $inner_blocks = is_array($blocks[$index]['innerBlocks'] ?? null) ? $blocks[$index]['innerBlocks'] : [];
        if ($operation === 'append') {
            $inner_blocks[] = $params['block'];
            // @mago-expect analysis:mixed-array-assignment
            $blocks[$index]['innerBlocks'] = $inner_blocks;
            return $blocks;
        }
        array_unshift($inner_blocks, $params['block']);
        // @mago-expect analysis:mixed-array-assignment
        $blocks[$index]['innerBlocks'] = $inner_blocks;
        return $blocks;
    }
    if ($operation === 'update-attrs') {
        $current_attrs = is_array($blocks[$index]['attrs'] ?? null) ? $blocks[$index]['attrs'] : [];
        $attrs = is_array($params['attrs'] ?? null) ? $params['attrs'] : [];
        // @mago-expect analysis:mixed-array-assignment
        $blocks[$index]['attrs'] = ($params['attrs_mode'] ?? 'merge') === 'replace'
            ? $attrs
            : array_merge($current_attrs, $attrs);
        return $blocks;
    }

    return new WP_Error('invalid_operation', 'Unsupported Gutenberg patch operation.');
}

/**
 * Return a post or a WP_Error.
 */
function novamira_get_post_or_error(int $post_id): WP_Post|WP_Error
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', 'Post not found.');
    }

    return $post;
}

/**
 * Create the backup payload.
 *
 * @return array<string, mixed>
 */
function novamira_create_builder_backup_entry(
    WP_Post $post,
    string $note,
    bool $include_post_content,
    bool $include_bricks_data,
): array {
    $payload = [];
    if ($include_post_content) {
        $payload['post_content'] = $post->post_content;
    }
    if ($include_bricks_data) {
        $payload['bricks'] = novamira_collect_bricks_backup_payload($post->ID);
    }

    return [
        'id' =>
            gmdate('YmdHis') . '-' . wp_generate_password(length: 8, special_chars: false, extra_special_chars: false),
        'created_at' => current_time(type: 'mysql', gmt: true),
        'created_by' => get_current_user_id(),
        'note' => $note,
        'post' => novamira_build_post_inventory_item($post),
        'content_hash' => novamira_hash_content($post->post_content),
        'payload' => $payload,
    ];
}

/**
 * Collect Bricks meta data for backup.
 *
 * @return array<string, array<string, mixed>>
 */
function novamira_collect_bricks_backup_payload(int $post_id): array
{
    $payload = [];
    foreach (novamira_get_bricks_meta_keys() as $area => $meta_key) {
        // @mago-expect analysis:mixed-assignment
        $value = get_post_meta($post_id, $meta_key, single: true);
        $payload[$area] = [
            'meta_key' => $meta_key,
            'has_data' => is_array($value) && $value !== [],
            'value' => is_array($value) ? $value : [],
        ];
    }

    return $payload;
}

/**
 * Return stored builder backups.
 *
 * @return list<array<array-key, mixed>>
 */
function novamira_get_builder_backups(int $post_id): array
{
    // @mago-expect analysis:mixed-assignment
    $backups = get_post_meta($post_id, NOVAMIRA_BUILDER_BACKUPS_META_KEY, single: true);
    if (!is_array($backups)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($backups as $backup) {
        if (!is_array($backup)) {
            continue;
        }
        $normalized[] = $backup;
    }

    return $normalized;
}

/**
 * Persist builder backups.
 *
 * @param list<array<array-key, mixed>> $backups
 */
function novamira_update_builder_backups(int $post_id, array $backups): void
{
    update_post_meta($post_id, NOVAMIRA_BUILDER_BACKUPS_META_KEY, $backups);
}

/**
 * Summarize a backup, optionally including the payload.
 *
 * @param array<array-key, mixed> $backup
 * @return array<string, mixed>
 */
function novamira_summarize_builder_backup(array $backup, bool $include_payload): array
{
    $summary = [
        'id' => (string) ($backup['id'] ?? ''),
        'created_at' => (string) ($backup['created_at'] ?? ''),
        'created_by' => (int) ($backup['created_by'] ?? 0),
        'note' => (string) ($backup['note'] ?? ''),
        'content_hash' => (string) ($backup['content_hash'] ?? ''),
        'post' => is_array($backup['post'] ?? null) ? $backup['post'] : [],
        'payload_summary' => novamira_summarize_backup_payload(
            is_array($backup['payload'] ?? null) ? $backup['payload'] : [],
        ),
    ];
    if ($include_payload) {
        $summary['payload'] = is_array($backup['payload'] ?? null) ? $backup['payload'] : [];
    }

    return $summary;
}

/**
 * Summarize backup payload without returning full content.
 *
 * @param array<array-key, mixed> $payload
 * @return array<string, mixed>
 */
function novamira_summarize_backup_payload(array $payload): array
{
    $bricks = is_array($payload['bricks'] ?? null) ? $payload['bricks'] : [];
    $bricks_counts = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($bricks as $area => $data) {
        if (!is_string($area) || !is_array($data)) {
            continue;
        }
        $value = is_array($data['value'] ?? null) ? $data['value'] : [];
        $bricks_counts[$area] = count($value);
    }

    return [
        'has_post_content' => is_string($payload['post_content'] ?? null),
        'post_content_bytes' => is_string($payload['post_content'] ?? null) ? strlen($payload['post_content']) : 0,
        'bricks_element_counts' => $bricks_counts,
    ];
}

/**
 * Hash content for optimistic write checks.
 */
function novamira_hash_content(string $content): string
{
    return hash('sha256', $content);
}
