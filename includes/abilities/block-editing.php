<?php

declare(strict_types=1);

/**
 * Abilities: Stable-ref Gutenberg block reads and dynamic block patches.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_BLOCK_REF_KEY = '_openmira_ref';

const OPENMIRA_BLOCK_REF_PREFIX = 'omr_';

wp_register_ability('openmira/read-blocks', [
    'label' => __('Read Blocks', domain: 'open-mira'),
    'description' => __(
        'Reads one post as a structured Gutenberg block tree with stable block refs. Reads are side-effect-free; virtual refs can be used with expected_etag for the first patch.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post ID to read.', 'minimum' => 1],
            'include_attrs' => [
                'type' => 'boolean',
                'description' => 'Whether to include block attributes in the tree.',
                'default' => true,
            ],
            'include_inner_html' => [
                'type' => 'boolean',
                'description' => 'Whether to include each block innerHTML value. Keep false for compact reads.',
                'default' => false,
            ],
            'max_depth' => [
                'type' => 'integer',
                'description' => 'Maximum innerBlocks depth to return.',
                'minimum' => 0,
                'maximum' => 20,
                'default' => 6,
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'etag' => ['type' => 'string'],
            'content_hash' => ['type' => 'string'],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['post', 'etag', 'content_hash', 'block_count', 'blocks', 'warnings'],
    ],
    'execute_callback' => 'openmira_read_blocks',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call before patch-blocks. Persisted refs are stable. Virtual refs are side-effect-free read refs and require expected_etag on patch.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/patch-blocks', [
    'label' => __('Patch Blocks', domain: 'open-mira'),
    'description' => __(
        'Applies an atomic batch of ref-addressed Gutenberg block operations. Dynamic blocks are patched in PHP; static blocks return a structured runtime-required error.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post ID to patch.', 'minimum' => 1],
            'expected_etag' => [
                'type' => 'string',
                'description' => 'Optional ETag from read-blocks. Required when using virtual refs.',
            ],
            'operations' => [
                'type' => 'array',
                'description' => 'Atomic block operations. v1 supports update, insert, and delete. Move is deferred.',
                'minItems' => 1,
                'maxItems' => 50,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => ['type' => 'string', 'enum' => ['update', 'insert', 'delete']],
                        'ref' => ['type' => 'string', 'description' => 'Target block ref for update/delete.'],
                        'before' => ['type' => 'string', 'description' => 'Anchor ref for insert-before.'],
                        'after' => ['type' => 'string', 'description' => 'Anchor ref for insert-after.'],
                        'parent_ref' => [
                            'type' => 'string',
                            'description' => 'Optional parent ref for index-based insert.',
                        ],
                        'index' => [
                            'type' => 'integer',
                            'description' => 'Zero-based index for parent/root insert.',
                            'minimum' => 0,
                        ],
                        'block_markup' => ['type' => 'string', 'description' => 'Serialized block markup for insert.'],
                        'attrs' => ['type' => 'object', 'description' => 'Attributes for update.'],
                        'attrs_mode' => [
                            'type' => 'string',
                            'description' => 'Whether attrs replace or merge with current attrs.',
                            'enum' => ['merge', 'replace'],
                            'default' => 'merge',
                        ],
                        'inner_html' => [
                            'type' => 'string',
                            'description' => 'Optional replacement innerHTML for a dynamic block fallback body.',
                        ],
                    ],
                    'required' => ['operation'],
                    'additionalProperties' => false,
                ],
            ],
            'create_backup' => [
                'type' => 'boolean',
                'description' => 'Whether to create a builder backup before patching.',
                'default' => true,
            ],
            'backup_note' => ['type' => 'string', 'description' => 'Optional backup note.', 'maxLength' => 500],
        ],
        'required' => ['post_id', 'operations'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post' => ['type' => 'object'],
            'previous_etag' => ['type' => 'string'],
            'new_etag' => ['type' => 'string'],
            'previous_hash' => ['type' => 'string'],
            'new_hash' => ['type' => 'string'],
            'operations_applied' => ['type' => 'integer'],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'backup' => ['type' => 'object'],
            'ref_changes' => ['type' => 'object'],
        ],
        'required' => [
            'post',
            'previous_etag',
            'new_etag',
            'previous_hash',
            'new_hash',
            'operations_applied',
            'block_count',
            'blocks',
            'ref_changes',
        ],
    ],
    'execute_callback' => 'openmira_patch_blocks',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use read-blocks first. Pass expected_etag for virtual refs. update/insert/delete are atomic and create one WordPress revision per successful batch.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Read a post as a side-effect-free block tree.
 *
 * @param array<array-key, mixed> $input
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_read_blocks(array $input): array|WP_Error
{
    $post = openmira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $blocks = parse_blocks($post->post_content);
    $warnings = [];
    $seen_refs = [];
    $options = [
        'include_attrs' => !openmira_falsey_input($input['include_attrs'] ?? true),
        'include_inner_html' => openmira_truthy_input($input['include_inner_html'] ?? false),
        'max_depth' => max(0, min(20, (int) ($input['max_depth'] ?? 6))),
    ];

    return [
        'post' => openmira_build_post_inventory_item($post),
        'etag' => openmira_block_etag($post),
        'content_hash' => openmira_hash_content($post->post_content),
        'block_count' => openmira_count_meaningful_blocks($blocks),
        'blocks' => openmira_shape_block_tree($blocks, $options, $seen_refs, $warnings),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

/**
 * Apply an atomic batch of dynamic Gutenberg block operations.
 *
 * @param array<array-key, mixed> $input
 * @return array<array-key, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_patch_blocks(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/patch-blocks');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $post = openmira_get_post_or_error((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $operations = is_array($input['operations'] ?? null) ? $input['operations'] : [];
    if ($operations === []) {
        return new WP_Error('missing_block_operations', 'operations must include at least one block operation.');
    }
    if (count($operations) > 50) {
        return new WP_Error('too_many_block_operations', 'A block patch batch may contain at most 50 operations.');
    }

    $previous_etag = openmira_block_etag($post);
    $expected_etag = (string) ($input['expected_etag'] ?? '');
    if ($expected_etag !== '' && $expected_etag !== $previous_etag) {
        return new WP_Error('block_etag_conflict', 'Post ETag does not match expected_etag.', [
            'expected_etag' => $expected_etag,
            'current_etag' => $previous_etag,
        ]);
    }

    $previous_hash = openmira_hash_content($post->post_content);
    $blocks = parse_blocks($post->post_content);
    $patched_blocks = $blocks;

    foreach ($operations as $offset => $operation) {
        if (!is_array($operation)) {
            return new WP_Error('invalid_block_operation', 'Each block operation must be an object.', [
                'operation_index' => $offset,
            ]);
        }
        $result = openmira_apply_ref_block_operation($patched_blocks, $operation, $previous_etag, $expected_etag);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $result->add_data(['operation_index' => (int) $offset] + (is_array($error_data) ? $error_data : []));
            return $result;
        }
        $patched_blocks = $result;
    }

    $ref_changes = openmira_normalize_block_refs_for_write($patched_blocks);
    // @mago-expect analysis:less-specific-nested-argument-type
    $content = serialize_blocks($patched_blocks);
    if ($content === $post->post_content) {
        return new WP_Error('block_patch_no_changes', 'The block patch produced no content changes.');
    }

    $backup = null;
    if (!openmira_falsey_input($input['create_backup'] ?? true)) {
        $backup_result = openmira_backup_builder_content([
            'post_id' => $post->ID,
            'note' => (string) ($input['backup_note'] ?? 'Automatic backup before block patch'),
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

    $update_result = openmira_update_post_content($post->ID, $content);
    if (is_wp_error($update_result)) {
        return $update_result;
    }

    $updated_post = openmira_get_post_or_error($post->ID);
    if (is_wp_error($updated_post)) {
        return $updated_post;
    }

    $read_options = [
        'include_attrs' => true,
        'include_inner_html' => false,
        'max_depth' => 6,
    ];
    $warnings = [];
    $seen_refs = [];
    $response = [
        'post' => openmira_build_post_inventory_item($updated_post),
        'previous_etag' => $previous_etag,
        'new_etag' => openmira_block_etag($updated_post),
        'previous_hash' => $previous_hash,
        'new_hash' => openmira_hash_content($updated_post->post_content),
        'operations_applied' => count($operations),
        'block_count' => openmira_count_meaningful_blocks(parse_blocks($updated_post->post_content)),
        'blocks' => openmira_shape_block_tree(
            parse_blocks($updated_post->post_content),
            $read_options,
            $seen_refs,
            $warnings,
        ),
        'ref_changes' => $ref_changes,
    ];
    if (is_array($backup)) {
        $response['backup'] = $backup;
    }

    return $response;
}

/**
 * Return a compact post-content ETag for block patches.
 */
function openmira_block_etag(WP_Post $post): string
{
    $modified =
        $post->post_modified_gmt !== '' && $post->post_modified_gmt !== '0000-00-00 00:00:00'
            ? $post->post_modified_gmt
            : $post->post_date_gmt;

    return 'omr:' . rawurlencode($modified) . ':' . substr(openmira_hash_content($post->post_content), 0, 12);
}

/**
 * Shape a list of parsed blocks for agent consumption.
 *
 * @param array<array-key, mixed>      $blocks
 * @param array<string, bool|int>      $options
 * @param array<string, int>           $seen_refs
 * @param list<string>                 $warnings
 * @param list<int>                    $path
 * @return list<array<string, mixed>>
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_shape_block_tree(
    array $blocks,
    array $options,
    array &$seen_refs,
    array &$warnings,
    array $path = [],
): array {
    $tree = [];
    foreach ($blocks as $index => $block) {
        if (!is_array($block) || !openmira_is_meaningful_block($block)) {
            continue;
        }

        $block_path = array_merge($path, [(int) $index]);
        $persisted_ref = openmira_get_persisted_block_ref($block);
        $ref_source = 'persisted';
        $ref = $persisted_ref;
        if ($ref === '') {
            $ref = openmira_virtual_block_ref($block_path, $block);
            $ref_source = 'virtual';
        } elseif (isset($seen_refs[$ref])) {
            $seen_refs[$ref]++;
            $ref = openmira_virtual_block_ref($block_path, $block);
            $ref_source = 'virtual_duplicate';
            $warnings[] = 'Duplicate persisted block ref detected; duplicate occurrence received a virtual ref.';
        } else {
            $seen_refs[$ref] = 1;
        }

        $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $entry = [
            'ref' => $ref,
            'ref_source' => $ref_source,
            'path' => $block_path,
            'name' => is_string($block['blockName'] ?? null) ? $block['blockName'] : 'core/freeform',
            'dynamic' => openmira_is_dynamic_block($block),
            'inner_block_count' => openmira_count_meaningful_blocks($inner_blocks),
        ];

        if (!openmira_falsey_input($options['include_attrs'] ?? true)) {
            $entry['attrs'] = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        }
        if (openmira_truthy_input($options['include_inner_html'] ?? false)) {
            $entry['inner_html'] = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        } else {
            $entry['inner_html_bytes'] = is_string($block['innerHTML'] ?? null) ? strlen($block['innerHTML']) : 0;
        }

        if ((int) ($options['max_depth'] ?? 6) > count($path) && $inner_blocks !== []) {
            $entry['innerBlocks'] = openmira_shape_block_tree(
                $inner_blocks,
                $options,
                $seen_refs,
                $warnings,
                $block_path,
            );
        }

        $tree[] = $entry;
    }

    return $tree;
}

/**
 * Apply one ref-addressed block operation to a parsed block list.
 *
 * @param array<array-key, mixed> $blocks
 * @param array<array-key, mixed> $operation
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_apply_ref_block_operation(
    array $blocks,
    array $operation,
    string $current_etag,
    string $expected_etag,
): array|WP_Error {
    $type = (string) ($operation['operation'] ?? '');
    if ($type === 'update') {
        return openmira_apply_update_block_operation($blocks, $operation, $current_etag, $expected_etag);
    }
    if ($type === 'insert') {
        return openmira_apply_insert_block_operation($blocks, $operation, $current_etag, $expected_etag);
    }
    if ($type === 'delete') {
        return openmira_apply_delete_block_operation($blocks, $operation, $current_etag, $expected_etag);
    }
    if ($type === 'move') {
        return new WP_Error(
            'block_move_deferred',
            'move is deferred from the first block-patch release; use delete plus insert for now.',
        );
    }

    return new WP_Error('invalid_block_operation', 'Unsupported block operation.');
}

/**
 * Apply an update operation.
 *
 * @param array<array-key, mixed> $blocks
 * @param array<array-key, mixed> $operation
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_apply_update_block_operation(
    array $blocks,
    array $operation,
    string $current_etag,
    string $expected_etag,
): array|WP_Error {
    $target = openmira_resolve_block_ref($blocks, (string) ($operation['ref'] ?? ''), $current_etag, $expected_etag);
    if (is_wp_error($target)) {
        return $target;
    }

    $block = openmira_get_block_at_path($blocks, $target['path']);
    if (is_wp_error($block)) {
        return $block;
    }
    if (!openmira_is_dynamic_block($block)) {
        return openmira_static_block_runtime_error($block);
    }

    $attrs_changed = array_key_exists('attrs', $operation);
    $inner_html_changed = array_key_exists('inner_html', $operation);
    if (!$attrs_changed && !$inner_html_changed) {
        return new WP_Error('empty_block_update', 'update requires attrs and/or inner_html.');
    }

    if ($attrs_changed) {
        if (!is_array($operation['attrs'])) {
            return new WP_Error('invalid_block_attrs', 'attrs must be an object.');
        }
        $current_attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $next_attrs = (string) ($operation['attrs_mode'] ?? 'merge') === 'replace'
            ? $operation['attrs']
            : openmira_recursive_merge($current_attrs, $operation['attrs']);
        $block['attrs'] = openmira_preserve_existing_block_ref($current_attrs, $next_attrs);
    }

    if ($inner_html_changed) {
        $inner_html = (string) ($operation['inner_html'] ?? '');
        $block['innerHTML'] = $inner_html;
        $block['innerContent'] = [$inner_html];
    }

    return openmira_set_block_at_path($blocks, $target['path'], $block);
}

/**
 * Apply an insert operation.
 *
 * @param array<array-key, mixed> $blocks
 * @param array<array-key, mixed> $operation
 * @return array<array-key, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_apply_insert_block_operation(
    array $blocks,
    array $operation,
    string $current_etag,
    string $expected_etag,
): array|WP_Error {
    $new_block = openmira_parse_single_block_markup((string) ($operation['block_markup'] ?? ''));
    if (is_wp_error($new_block)) {
        return $new_block;
    }
    if (!openmira_is_dynamic_block($new_block)) {
        return openmira_static_block_runtime_error($new_block);
    }

    if (is_string($operation['before'] ?? null) && $operation['before'] !== '') {
        $anchor = openmira_resolve_block_ref($blocks, $operation['before'], $current_etag, $expected_etag);
        if (is_wp_error($anchor)) {
            return $anchor;
        }
        return openmira_insert_block_at_sibling_path($blocks, $anchor['path'], $new_block, before: true);
    }

    if (is_string($operation['after'] ?? null) && $operation['after'] !== '') {
        $anchor = openmira_resolve_block_ref($blocks, $operation['after'], $current_etag, $expected_etag);
        if (is_wp_error($anchor)) {
            return $anchor;
        }
        return openmira_insert_block_at_sibling_path($blocks, $anchor['path'], $new_block, before: false);
    }

    $parent_path = [];
    if (is_string($operation['parent_ref'] ?? null) && $operation['parent_ref'] !== '') {
        $parent = openmira_resolve_block_ref($blocks, $operation['parent_ref'], $current_etag, $expected_etag);
        if (is_wp_error($parent)) {
            return $parent;
        }
        $parent_path = $parent['path'];
    }
    $index = array_key_exists('index', $operation) ? (int) $operation['index'] : PHP_INT_MAX;

    return openmira_insert_block_at_parent_path($blocks, $parent_path, $index, $new_block);
}

/**
 * Apply a delete operation.
 *
 * @param array<array-key, mixed> $blocks
 * @param array<array-key, mixed> $operation
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_apply_delete_block_operation(
    array $blocks,
    array $operation,
    string $current_etag,
    string $expected_etag,
): array|WP_Error {
    $target = openmira_resolve_block_ref($blocks, (string) ($operation['ref'] ?? ''), $current_etag, $expected_etag);
    if (is_wp_error($target)) {
        return $target;
    }

    $block = openmira_get_block_at_path($blocks, $target['path']);
    if (is_wp_error($block)) {
        return $block;
    }
    if (!openmira_is_dynamic_block($block)) {
        return openmira_static_block_runtime_error($block);
    }

    return openmira_remove_block_at_path($blocks, $target['path']);
}

/**
 * Resolve a block ref to a path in the current block tree.
 *
 * @param array<array-key, mixed> $blocks
 * @return array{path: list<int>, ref_source: string}|WP_Error
 */
function openmira_resolve_block_ref(
    array $blocks,
    string $ref,
    string $current_etag,
    string $expected_etag,
): array|WP_Error {
    if ($ref === '') {
        return new WP_Error('missing_block_ref', 'A block ref is required for this operation.');
    }

    if (str_starts_with($ref, 'v:')) {
        if ($expected_etag === '') {
            return new WP_Error(
                'expected_etag_required',
                'expected_etag is required when patching with a virtual block ref.',
            );
        }
        if ($expected_etag !== $current_etag) {
            return new WP_Error('block_etag_conflict', 'Virtual block ref no longer matches the current post ETag.', [
                'expected_etag' => $expected_etag,
                'current_etag' => $current_etag,
            ]);
        }
        return openmira_resolve_virtual_block_ref($blocks, $ref);
    }

    $matches = [];
    openmira_find_persisted_ref_paths($blocks, $ref, $matches);
    if ($matches === []) {
        return new WP_Error('block_ref_not_found', 'The requested block ref was not found.', ['ref' => $ref]);
    }
    if (count($matches) > 1) {
        return new WP_Error(
            'duplicate_block_ref',
            'The requested persisted block ref appears more than once; target by virtual ref after re-reading.',
            [
                'ref' => $ref,
                'matches' => $matches,
            ],
        );
    }

    return ['path' => $matches[0], 'ref_source' => 'persisted'];
}

/**
 * Resolve a side-effect-free virtual ref.
 *
 * @param array<array-key, mixed> $blocks
 * @return array{path: list<int>, ref_source: string}|WP_Error
 */
function openmira_resolve_virtual_block_ref(array $blocks, string $ref): array|WP_Error
{
    $match = [];
    if (!preg_match('/^v:([0-9.]+):([a-f0-9]{12})$/', $ref, $match)) {
        return new WP_Error('invalid_virtual_block_ref', 'Virtual block ref format is invalid.');
    }
    $path = array_map('intval', explode('.', $match[1]));
    $block = openmira_get_block_at_path($blocks, $path);
    if (is_wp_error($block)) {
        return new WP_Error('virtual_block_ref_not_found', 'The virtual block ref path no longer exists.', [
            'ref' => $ref,
        ]);
    }
    if (openmira_virtual_block_ref($path, $block) !== $ref) {
        return new WP_Error(
            'virtual_block_ref_stale',
            'The virtual block ref no longer matches the block at its path.',
            [
                'ref' => $ref,
            ],
        );
    }

    return ['path' => $path, 'ref_source' => 'virtual'];
}

/**
 * Build a virtual ref from a path and block signature.
 *
 * @param list<int>            $path
 * @param array<array-key, mixed> $block
 */
function openmira_virtual_block_ref(array $path, array $block): string
{
    return 'v:' . implode('.', $path) . ':' . substr(hash('sha256', openmira_block_signature($block)), 0, 12);
}

/**
 * Return a stable signature for virtual-ref verification.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_block_signature(array $block): string
{
    $inner_blocks = [];
    if (is_array($block['innerBlocks'] ?? null)) {
        foreach ($block['innerBlocks'] as $inner_block) {
            if (is_array($inner_block)) {
                $inner_blocks[] = openmira_block_signature($inner_block);
            }
        }
    }

    return (
        wp_json_encode([
            'name' => is_string($block['blockName'] ?? null) ? $block['blockName'] : null,
            'attrs' => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
            'innerHTML' => is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '',
            'innerContent' => is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [],
            'innerBlocks' => $inner_blocks,
        ]) ?: ''
    );
}

/**
 * Find all paths for a persisted ref.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<list<int>>         $matches
 * @param list<int>               $path
 */
function openmira_find_persisted_ref_paths(array $blocks, string $ref, array &$matches, array $path = []): void
{
    foreach ($blocks as $index => $block) {
        if (!is_array($block) || !openmira_is_meaningful_block($block)) {
            continue;
        }
        $block_path = array_merge($path, [(int) $index]);
        if (openmira_get_persisted_block_ref($block) === $ref) {
            $matches[] = $block_path;
        }
        if (is_array($block['innerBlocks'] ?? null)) {
            openmira_find_persisted_ref_paths($block['innerBlocks'], $ref, $matches, $block_path);
        }
    }
}

/**
 * Return a block at a path.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $path
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_get_block_at_path(array $blocks, array $path): array|WP_Error
{
    if ($path === []) {
        return new WP_Error('missing_block_path', 'Block path cannot be empty.');
    }
    $index = array_shift($path);
    if (!array_key_exists($index, $blocks) || !is_array($blocks[$index])) {
        return new WP_Error('block_path_not_found', 'The requested block path does not exist.');
    }
    if ($path === []) {
        return $blocks[$index];
    }
    $current_block = $blocks[$index];
    $inner_blocks = is_array($current_block['innerBlocks'] ?? null) ? $current_block['innerBlocks'] : [];

    return openmira_get_block_at_path($inner_blocks, $path);
}

/**
 * Set a block at a path.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $path
 * @param array<array-key, mixed> $block
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_set_block_at_path(array $blocks, array $path, array $block): array|WP_Error
{
    $index = array_shift($path);
    if ($index === null || !array_key_exists($index, $blocks) || !is_array($blocks[$index])) {
        return new WP_Error('block_path_not_found', 'The requested block path does not exist.');
    }
    if ($path === []) {
        $blocks[$index] = $block;
        return $blocks;
    }
    $current_block = $blocks[$index];
    $inner_blocks = is_array($current_block['innerBlocks'] ?? null) ? $current_block['innerBlocks'] : [];
    $patched_inner = openmira_set_block_at_path($inner_blocks, $path, $block);
    if (is_wp_error($patched_inner)) {
        return $patched_inner;
    }
    $current_block['innerBlocks'] = $patched_inner;
    $blocks[$index] = $current_block;

    return $blocks;
}

/**
 * Insert a block before or after an anchor path.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $anchor_path
 * @param array<array-key, mixed> $block
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_insert_block_at_sibling_path(
    array $blocks,
    array $anchor_path,
    array $block,
    bool $before,
): array|WP_Error {
    $index = array_pop($anchor_path);
    if ($index === null) {
        return new WP_Error('invalid_insert_anchor', 'Insert anchor path is invalid.');
    }
    return openmira_insert_block_at_parent_path($blocks, $anchor_path, $before ? $index : $index + 1, $block);
}

/**
 * Insert a block at a parent path and child index.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $parent_path
 * @param array<array-key, mixed> $block
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_insert_block_at_parent_path(
    array $blocks,
    array $parent_path,
    int $index,
    array $block,
): array|WP_Error {
    if ($parent_path === []) {
        $index = max(0, min($index, count($blocks)));
        array_splice($blocks, $index, 0, [$block]);
        return $blocks;
    }

    $parent = openmira_get_block_at_path($blocks, $parent_path);
    if (is_wp_error($parent)) {
        return $parent;
    }
    $inner_blocks = is_array($parent['innerBlocks'] ?? null) ? $parent['innerBlocks'] : [];
    $index = max(0, min($index, count($inner_blocks)));
    array_splice($inner_blocks, $index, 0, [$block]);
    $parent['innerBlocks'] = $inner_blocks;
    $parent['innerContent'] = openmira_insert_inner_content_placeholder(
        is_array($parent['innerContent'] ?? null) ? $parent['innerContent'] : [],
        $index,
    );

    return openmira_set_block_at_path($blocks, $parent_path, $parent);
}

/**
 * Remove a block at a path.
 *
 * @param array<array-key, mixed> $blocks
 * @param list<int>              $path
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_remove_block_at_path(array $blocks, array $path): array|WP_Error
{
    $index = array_pop($path);
    if ($index === null) {
        return new WP_Error('missing_block_path', 'Block path cannot be empty.');
    }

    if ($path === []) {
        if (!array_key_exists($index, $blocks)) {
            return new WP_Error('block_path_not_found', 'The requested block path does not exist.');
        }
        array_splice($blocks, $index, 1);
        return $blocks;
    }

    $parent = openmira_get_block_at_path($blocks, $path);
    if (is_wp_error($parent)) {
        return $parent;
    }
    $inner_blocks = is_array($parent['innerBlocks'] ?? null) ? $parent['innerBlocks'] : [];
    if (!array_key_exists($index, $inner_blocks)) {
        return new WP_Error('block_path_not_found', 'The requested block path does not exist.');
    }
    array_splice($inner_blocks, $index, 1);
    $parent['innerBlocks'] = $inner_blocks;
    $parent['innerContent'] = openmira_remove_inner_content_placeholder(
        is_array($parent['innerContent'] ?? null) ? $parent['innerContent'] : [],
        $index,
    );

    return openmira_set_block_at_path($blocks, $path, $parent);
}

/**
 * Insert a null placeholder at the Nth child slot in innerContent.
 *
 * @param array<array-key, mixed> $inner_content
 * @return array<array-key, mixed>
 */
function openmira_insert_inner_content_placeholder(array $inner_content, int $child_index): array
{
    $nulls_seen = 0;
    foreach ($inner_content as $offset => $part) {
        if ($part !== null) {
            continue;
        }
        if ($nulls_seen === $child_index) {
            array_splice($inner_content, (int) $offset, 0, [null]);
            return $inner_content;
        }
        $nulls_seen++;
    }
    $inner_content[] = null;

    return $inner_content;
}

/**
 * Remove the Nth null placeholder from innerContent.
 *
 * @param array<array-key, mixed> $inner_content
 * @return array<array-key, mixed>
 */
function openmira_remove_inner_content_placeholder(array $inner_content, int $child_index): array
{
    $nulls_seen = 0;
    foreach ($inner_content as $offset => $part) {
        if ($part !== null) {
            continue;
        }
        if ($nulls_seen === $child_index) {
            array_splice($inner_content, (int) $offset, 1);
            return $inner_content;
        }
        $nulls_seen++;
    }

    return $inner_content;
}

/**
 * Assign durable refs to blocks that need them before a write.
 *
 * @param array<array-key, mixed> $blocks
 * @return array{assigned: list<string>, rewritten_duplicates: list<string>}
 */
function openmira_normalize_block_refs_for_write(array &$blocks): array
{
    $seen = [];
    $changes = [
        'assigned' => [],
        'rewritten_duplicates' => [],
    ];
    openmira_normalize_block_refs_for_write_inner($blocks, $seen, $changes);

    return $changes;
}

/**
 * Recursive durable-ref normalizer.
 *
 * @param array<array-key, mixed>                                      $blocks
 * @param array<string, bool>                                          $seen
 * @param array{assigned: list<string>, rewritten_duplicates: list<string>} $changes
 */
function openmira_normalize_block_refs_for_write_inner(array &$blocks, array &$seen, array &$changes): void
{
    $block = null;
    foreach ($blocks as &$block) {
        if (!is_array($block) || !openmira_is_meaningful_block($block)) {
            continue;
        }
        $ref = openmira_get_persisted_block_ref($block);
        $needs_ref = $ref === '' || str_starts_with($ref, 'v:') || isset($seen[$ref]);
        if ($needs_ref) {
            $old_ref = $ref;
            $ref = openmira_new_block_ref($seen);
            openmira_set_persisted_block_ref($block, $ref);
            $changes[$old_ref === '' || str_starts_with($old_ref, 'v:') ? 'assigned' : 'rewritten_duplicates'][] = $ref;
        }
        $seen[$ref] = true;
        if (is_array($block['innerBlocks'] ?? null)) {
            openmira_normalize_block_refs_for_write_inner($block['innerBlocks'], $seen, $changes);
        }
    }
    unset($block);
}

/**
 * Create a new durable block ref.
 *
 * @param array<string, bool>                                          $seen
 */
function openmira_new_block_ref(array $seen): string
{
    do {
        $ref = OPENMIRA_BLOCK_REF_PREFIX . str_replace('-', '', (string) wp_generate_uuid4());
    } while (isset($seen[$ref]));

    return $ref;
}

/**
 * Return a persisted block ref from attrs.metadata.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_get_persisted_block_ref(array $block): string
{
    $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
    $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
    $ref = $metadata[OPENMIRA_BLOCK_REF_KEY] ?? '';

    return is_string($ref) ? $ref : '';
}

/**
 * Set a persisted block ref under attrs.metadata.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_set_persisted_block_ref(array &$block, string $ref): void
{
    $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
    $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
    $metadata[OPENMIRA_BLOCK_REF_KEY] = $ref;
    $attrs['metadata'] = $metadata;
    $block['attrs'] = $attrs;
}

/**
 * Preserve an existing persisted ref when attrs are replaced.
 *
 * @param array<array-key, mixed> $current_attrs
 * @param array<array-key, mixed> $next_attrs
 * @return array<array-key, mixed>
 */
function openmira_preserve_existing_block_ref(array $current_attrs, array $next_attrs): array
{
    $current_metadata = is_array($current_attrs['metadata'] ?? null) ? $current_attrs['metadata'] : [];
    $current_ref = is_string($current_metadata[OPENMIRA_BLOCK_REF_KEY] ?? null)
        ? $current_metadata[OPENMIRA_BLOCK_REF_KEY]
        : '';
    if ($current_ref === '') {
        return $next_attrs;
    }

    $next_metadata = is_array($next_attrs['metadata'] ?? null) ? $next_attrs['metadata'] : [];
    if (!is_string($next_metadata[OPENMIRA_BLOCK_REF_KEY] ?? null)) {
        $next_metadata[OPENMIRA_BLOCK_REF_KEY] = $current_ref;
        $next_attrs['metadata'] = $next_metadata;
    }

    return $next_attrs;
}

/**
 * Recursive array merge for attrs.
 *
 * @param array<array-key, mixed> $base
 * @param array<array-key, mixed> $patch
 * @return array<array-key, mixed>
 */
function openmira_recursive_merge(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if (is_array($value) && is_array($base[$key] ?? null)) {
            $base[$key] = openmira_recursive_merge($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}

/**
 * Determine whether a parsed block is meaningful.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_is_meaningful_block(array $block): bool
{
    $name = $block['blockName'] ?? null;
    $inner_html = trim(is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '');

    return $name !== null || $inner_html !== '';
}

/**
 * Count meaningful blocks recursively.
 *
 * @param array<array-key, mixed> $blocks
 */
function openmira_count_meaningful_blocks(array $blocks): int
{
    $count = 0;
    foreach ($blocks as $block) {
        if (!is_array($block) || !openmira_is_meaningful_block($block)) {
            continue;
        }
        $count++;
        if (is_array($block['innerBlocks'] ?? null)) {
            $count += openmira_count_meaningful_blocks($block['innerBlocks']);
        }
    }

    return $count;
}

/**
 * Determine whether a parsed block is server-rendered/dynamic.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_is_dynamic_block(array $block): bool
{
    $name = $block['blockName'] ?? null;
    if (!is_string($name) || $name === '') {
        return false;
    }

    $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
    if (!$type instanceof WP_Block_Type) {
        return false;
    }
    if (method_exists($type, 'is_dynamic')) {
        return $type->is_dynamic();
    }

    return false;
}

/**
 * Return a structured static-runtime error.
 *
 * @param array<array-key, mixed> $block
 */
function openmira_static_block_runtime_error(array $block): WP_Error
{
    return new WP_Error(
        'block_runtime_required',
        'This operation touches a static block and requires the Block Editor Runtime path.',
        [
            'block_name' => is_string($block['blockName'] ?? null) ? $block['blockName'] : 'core/freeform',
            'runtime_status' => 'not_implemented',
        ],
    );
}
