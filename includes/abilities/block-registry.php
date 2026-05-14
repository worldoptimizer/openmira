<?php

declare(strict_types=1);

/**
 * Abilities: Gutenberg block registry discovery and server-side content validation.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/list-block-types', [
    'label' => __('List Block Types', domain: 'open-mira'),
    'description' => __(
        'Lists registered Gutenberg block types from WP_Block_Type_Registry, including core and third-party blocks. This exposes server-known block.json metadata, attributes, supports, parent/ancestor constraints, and script/style handles.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => [
                'type' => 'string',
                'description' => 'Optional search across name, title, category, and description.',
            ],
            'namespace' => [
                'type' => 'string',
                'description' => 'Optional namespace filter, for example "core", "acf", or "woocommerce".',
            ],
            'include_core' => ['type' => 'boolean', 'description' => 'Include core/* blocks.', 'default' => true],
            'include_third_party' => [
                'type' => 'boolean',
                'description' => 'Include non-core blocks.',
                'default' => true,
            ],
            'include_schema' => [
                'type' => 'boolean',
                'description' => 'Include attributes and supports in each result.',
                'default' => false,
            ],
            'limit' => ['type' => 'integer', 'description' => 'Maximum blocks to return.', 'default' => 200],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
            'total_registered' => ['type' => 'integer'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['blocks', 'count', 'total_registered', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_list_block_types',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this before generating markup for core or third-party blocks. For exact saved HTML, prefer editor JS serialization when available.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/get-block-type', [
    'label' => __('Get Block Type', domain: 'open-mira'),
    'description' => __(
        'Returns detailed server-side metadata for a registered Gutenberg block type. Useful for discovering third-party block attributes, parent/ancestor constraints, dynamic render status, and asset handles.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Registered block name, for example core/group.',
                'minLength' => 1,
            ],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'block' => ['type' => 'object'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['block', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_get_block_type',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'This reveals registered metadata, not guaranteed static save() HTML. Use editor-side serialization for exact markup.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/validate-gutenberg-content', [
    'label' => __('Validate Gutenberg Content', domain: 'open-mira'),
    'description' => __(
        'Validates Gutenberg post_content server-side by parsing blocks and checking registered block names, unknown attrs, and parent/ancestor/allowed child constraints. It cannot fully validate static save() HTML mismatches; Gutenberg editor JS is authoritative for that.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'content' => ['type' => 'string', 'description' => 'Raw Gutenberg post_content to validate.'],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Optional post ID. If content is omitted, validate this post_content.',
                'minimum' => 1,
            ],
            'include_parsed_blocks' => [
                'type' => 'boolean',
                'description' => 'Whether to include parse_blocks output.',
                'default' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'valid_server_side' => ['type' => 'boolean'],
            'can_validate_editor_serialization' => ['type' => 'boolean'],
            'errors' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'object']],
            'block_count' => ['type' => 'integer'],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'parsed_blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => [
            'valid_server_side',
            'can_validate_editor_serialization',
            'errors',
            'warnings',
            'block_count',
            'blocks',
            'serialization_note',
        ],
    ],
    'execute_callback' => 'openmira_validate_gutenberg_content',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use before writes. Treat valid_server_side as necessary but not sufficient for editor validity; exact save() mismatches require editor JS serialization.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/create-gutenberg-serialization-job', [
    'label' => __('Create Gutenberg Serialization Job', domain: 'open-mira'),
    'description' => __(
        'Creates a browser-backed Gutenberg serialization job. Open the returned admin URL in an authenticated browser; WordPress editor JavaScript will serialize the block spec and save the exact markup for MCP to read.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'spec' => [
                'type' => 'array',
                'description' => 'Array of top-level block spec objects. Each spec supports name, attrs, and innerBlocks.',
                'items' => ['type' => 'object'],
                'minItems' => 1,
            ],
        ],
        'required' => ['spec'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
            'serializer_url' => ['type' => 'string'],
            'instructions' => ['type' => 'string'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['job', 'serializer_url', 'instructions', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_create_gutenberg_serialization_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for exact static block save() markup. After creating the job, open serializer_url in an authenticated browser, then call read-gutenberg-serialization-job.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/read-gutenberg-serialization-job', [
    'label' => __('Read Gutenberg Serialization Job', domain: 'open-mira'),
    'description' => __(
        'Reads a browser-backed Gutenberg serialization job created by Open Mira. Returns exact editor-generated markup after the admin serializer page has run.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Serialization job ID.', 'minLength' => 1],
            'include_spec' => [
                'type' => 'boolean',
                'description' => 'Include the original block spec.',
                'default' => false,
            ],
            'include_markup' => ['type' => 'boolean', 'description' => 'Include serialized markup.', 'default' => true],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
            'serializer_url' => ['type' => 'string'],
            'complete' => ['type' => 'boolean'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['job', 'serializer_url', 'complete', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_read_gutenberg_serialization_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Poll this after opening serializer_url. If status is complete, pass markup to write-gutenberg-content or create-gutenberg-page.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/create-gutenberg-block-profile-job', [
    'label' => __('Create Gutenberg Block Profile Job', domain: 'open-mira'),
    'description' => __(
        'Creates a browser-backed job that probes Gutenberg block types with editor JavaScript, serializes candidate specs, parses the result, and caches safe/unsafe generation profiles.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'names' => [
                'type' => 'array',
                'description' => 'Optional explicit block names to profile.',
                'items' => ['type' => 'string'],
            ],
            'namespace' => [
                'type' => 'string',
                'description' => 'Optional namespace filter, for example core or kadence.',
            ],
            'search' => ['type' => 'string', 'description' => 'Optional search filter.'],
            'include_core' => ['type' => 'boolean', 'description' => 'Include core/* blocks.', 'default' => true],
            'include_third_party' => [
                'type' => 'boolean',
                'description' => 'Include non-core blocks.',
                'default' => true,
            ],
            'limit' => ['type' => 'integer', 'description' => 'Maximum blocks to profile.', 'default' => 25],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
            'profiler_url' => ['type' => 'string'],
            'instructions' => ['type' => 'string'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['job', 'profiler_url', 'instructions', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_create_gutenberg_block_profile_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this after plugins/themes change or before generating against an unfamiliar block namespace. Open profiler_url, then read the job.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/read-gutenberg-block-profile-job', [
    'label' => __('Read Gutenberg Block Profile Job', domain: 'open-mira'),
    'description' => __('Reads a completed browser-backed Gutenberg block profile job.', domain: 'open-mira'),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Profile job ID.', 'minLength' => 1],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
            'profiler_url' => ['type' => 'string'],
            'complete' => ['type' => 'boolean'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['job', 'profiler_url', 'complete', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_read_gutenberg_block_profile_job',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this after opening profiler_url. Safe profiles can seed generation; unsafe profiles should fall back to core wrappers or adapters.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/list-gutenberg-block-profiles', [
    'label' => __('List Gutenberg Block Profiles', domain: 'open-mira'),
    'description' => __(
        'Lists cached Gutenberg block safety profiles created by browser-backed profile jobs.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-builders',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'namespace' => ['type' => 'string', 'description' => 'Optional namespace filter.'],
            'safe_only' => [
                'type' => 'boolean',
                'description' => 'Only return profiles classified as safe.',
                'default' => false,
            ],
            'limit' => ['type' => 'integer', 'description' => 'Maximum profiles to return.', 'default' => 100],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'profiles' => ['type' => 'array', 'items' => ['type' => 'object']],
            'count' => ['type' => 'integer'],
            'total_cached' => ['type' => 'integer'],
            'serialization_note' => ['type' => 'string'],
        ],
        'required' => ['profiles', 'count', 'total_cached', 'serialization_note'],
    ],
    'execute_callback' => 'openmira_list_gutenberg_block_profiles',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use cached profiles to choose generation-safe blocks before writing Gutenberg content.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List registered Gutenberg block types.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_list_block_types(array $input = []): array
{
    $registry = WP_Block_Type_Registry::get_instance();
    $all_blocks = $registry->get_all_registered();
    $search = strtolower(trim((string) ($input['search'] ?? '')));
    $namespace = sanitize_key((string) ($input['namespace'] ?? ''));
    $include_schema = openmira_truthy_input($input['include_schema'] ?? false);
    $include_core = !openmira_falsey_input($input['include_core'] ?? true);
    $include_third_party = !openmira_falsey_input($input['include_third_party'] ?? true);
    $limit = openmira_normalize_limit($input['limit'] ?? 200);

    $blocks = [];
    foreach ($all_blocks as $block_type) {
        if (!openmira_block_type_matches_filters(
            $block_type,
            $search,
            $namespace,
            $include_core,
            $include_third_party,
        )) {
            continue;
        }

        $blocks[] = openmira_summarize_block_type($block_type, $include_schema);
        if (count($blocks) >= $limit) {
            break;
        }
    }

    return [
        'blocks' => $blocks,
        'count' => count($blocks),
        'total_registered' => count($all_blocks),
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Get one registered block type.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_get_block_type(array $input): array|WP_Error
{
    $name = (string) ($input['name'] ?? '');
    $block_type = WP_Block_Type_Registry::get_instance()->get_registered($name);
    if (!$block_type instanceof WP_Block_Type) {
        return new WP_Error('block_type_not_found', 'Block type is not registered.');
    }

    return [
        'block' => openmira_describe_block_type($block_type),
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Validate Gutenberg content using server-side facts.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_validate_gutenberg_content(array $input): array|WP_Error
{
    $content = (string) ($input['content'] ?? '');
    if ($content === '' && array_key_exists('post_id', $input)) {
        $post = openmira_get_post_or_error((int) $input['post_id']);
        if (is_wp_error($post)) {
            return $post;
        }
        $content = $post->post_content;
    }
    if ($content === '') {
        return new WP_Error('missing_content', 'Provide content or post_id.');
    }

    $parsed_blocks = openmira_normalize_parsed_blocks(parse_blocks($content));
    $errors = [];
    $warnings = [];
    openmira_validate_parsed_blocks($parsed_blocks, [], [], $errors, $warnings);

    $response = [
        'valid_server_side' => $errors === [],
        'can_validate_editor_serialization' => false,
        'errors' => $errors,
        'warnings' => $warnings,
        'block_count' => openmira_count_named_blocks($parsed_blocks),
        'blocks' => openmira_summarize_blocks($parsed_blocks),
        'serialization_note' => openmira_block_serialization_note(),
    ];
    if (openmira_truthy_input($input['include_parsed_blocks'] ?? false)) {
        $response['parsed_blocks'] = $parsed_blocks;
    }

    return $response;
}

/**
 * Create a browser-backed editor JS serialization job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_create_gutenberg_serialization_job(array $input): array|WP_Error
{
    if (!is_array($input['spec'] ?? null)) {
        return new WP_Error('missing_block_spec', 'Provide a block spec object or an array of block spec objects.');
    }

    $spec = $input['spec'];
    $job = openmira_create_block_serialization_job($spec);
    if (is_wp_error($job)) {
        return $job;
    }

    return [
        'job' => openmira_public_block_serialization_job($job, [
            'include_spec' => true,
            'include_markup' => false,
        ]),
        'serializer_url' => (string) $job['serializer_url'],
        'instructions' => 'Open serializer_url in an authenticated WordPress admin browser. The page auto-runs wp.blocks.createBlock/wp.blocks.serialize, stores the result, then read it with openmira/read-gutenberg-serialization-job.',
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Read a browser-backed editor JS serialization job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_read_gutenberg_serialization_job(array $input): array|WP_Error
{
    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_job_id', 'Provide a serialization job_id.');
    }

    $job = openmira_get_block_serialization_job($job_id);
    if ($job === null) {
        return new WP_Error('serialization_job_not_found', 'Serialization job not found or expired.');
    }

    $include_spec = openmira_truthy_input($input['include_spec'] ?? false);
    $include_markup = !openmira_falsey_input($input['include_markup'] ?? true);

    return [
        'job' => openmira_public_block_serialization_job($job, [
            'include_spec' => $include_spec,
            'include_markup' => $include_markup,
        ]),
        'serializer_url' => openmira_get_block_serialization_job_url($job_id),
        'complete' => ($job['status'] ?? '') === 'complete',
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Create a browser-backed block profile job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_create_gutenberg_block_profile_job(array $input = []): array|WP_Error
{
    $block_names = openmira_resolve_block_profile_names($input);
    if ($block_names === []) {
        return new WP_Error('no_blocks_to_profile', 'No registered block types matched the profile request.');
    }

    $job = openmira_create_block_profile_job($block_names);
    if (is_wp_error($job)) {
        return $job;
    }

    return [
        'job' => openmira_public_block_tool_job($job),
        'profiler_url' => (string) $job['serializer_url'],
        'instructions' => 'Open profiler_url in an authenticated WordPress admin browser. The page loads editor block scripts, probes each block, caches results, then read it with openmira/read-gutenberg-block-profile-job.',
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Read a browser-backed block profile job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_read_gutenberg_block_profile_job(array $input): array|WP_Error
{
    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_job_id', 'Provide a profile job_id.');
    }

    $job = openmira_get_block_serialization_job($job_id);
    if ($job === null || ($job['kind'] ?? '') !== 'profile') {
        return new WP_Error('profile_job_not_found', 'Profile job not found or expired.');
    }

    return [
        'job' => openmira_public_block_tool_job($job),
        'profiler_url' => openmira_get_block_serialization_job_url($job_id),
        'complete' => ($job['status'] ?? '') === 'complete',
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * List cached Gutenberg block profiles.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_list_gutenberg_block_profiles(array $input = []): array
{
    $profiles = openmira_get_gutenberg_block_profiles();
    $namespace = sanitize_key((string) ($input['namespace'] ?? ''));
    $safe_only = openmira_truthy_input($input['safe_only'] ?? false);
    $limit = openmira_normalize_limit($input['limit'] ?? 100);

    $filtered = [];
    foreach ($profiles as $profile) {
        $name = (string) ($profile['name'] ?? '');
        if ($namespace !== '' && !str_starts_with($name, $namespace . '/')) {
            continue;
        }
        if ($safe_only && !openmira_truthy_input($profile['safe'] ?? false)) {
            continue;
        }
        $filtered[] = $profile;
        if (count($filtered) >= $limit) {
            break;
        }
    }

    return [
        'profiles' => $filtered,
        'count' => count($filtered),
        'total_cached' => count($profiles),
        'serialization_note' => openmira_block_serialization_note(),
    ];
}

/**
 * Return a safe serialization job payload for MCP responses.
 *
 * @param array<array-key, mixed> $job
 * @return array<string, mixed>
 */
function openmira_public_block_serialization_job(array $job, array $options = []): array
{
    $public = openmira_public_block_tool_job($job);
    unset($public['block_names'], $public['profiles']);

    $include_spec = openmira_truthy_input($options['include_spec'] ?? false);
    $include_markup = !openmira_falsey_input($options['include_markup'] ?? true);
    if ($include_spec) {
        $public['spec'] = $job['spec'] ?? [];
    }
    if ($include_markup) {
        $public['markup'] = (string) ($job['markup'] ?? '');
    }

    return $public;
}

/**
 * Return a safe block tool job payload for MCP responses.
 *
 * @param array<array-key, mixed> $job
 * @return array<string, mixed>
 */
function openmira_public_block_tool_job(array $job): array
{
    $public = [
        'job_id' => (string) ($job['job_id'] ?? ''),
        'kind' => (string) ($job['kind'] ?? 'serialize'),
        'status' => (string) ($job['status'] ?? 'pending'),
        'spec_hash' => (string) ($job['spec_hash'] ?? ''),
        'error' => (string) ($job['error'] ?? ''),
        'loaded_block_count' => (int) ($job['loaded_block_count'] ?? 0),
        'top_level_block_count' => (int) ($job['top_level_block_count'] ?? 0),
        'created_at' => gmdate(DATE_ATOM, (int) ($job['created_at'] ?? time())),
        'completed_at' => (int) ($job['completed_at'] ?? 0) > 0 ? gmdate(DATE_ATOM, (int) $job['completed_at']) : '',
    ];
    if (($job['kind'] ?? '') === 'profile') {
        openmira_add_public_block_profile_fields($public, $job);
    }

    return $public;
}

/**
 * Add profile-specific fields to a public job payload.
 *
 * @param array<string, mixed>      $public
 * @param array<array-key, mixed>   $job
 */
function openmira_add_public_block_profile_fields(array &$public, array $job): void
{
    $public['block_names'] = is_array($job['block_names'] ?? null) ? array_values($job['block_names']) : [];
    $public['profiles'] = is_array($job['profiles'] ?? null) ? array_values($job['profiles']) : [];
    $public['profile_count'] = count($public['profiles']);
}

/**
 * Resolve block names for a profile request.
 *
 * @param array<string, mixed> $input
 * @return list<string>
 */
function openmira_resolve_block_profile_names(array $input): array
{
    if (is_array($input['names'] ?? null)) {
        $names = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($input['names'] as $name) {
            if (!is_string($name) || !WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                continue;
            }
            $names[$name] = $name;
        }

        return array_values($names);
    }

    $registry = WP_Block_Type_Registry::get_instance();
    $search = strtolower(trim((string) ($input['search'] ?? '')));
    $namespace = sanitize_key((string) ($input['namespace'] ?? ''));
    $include_core = !openmira_falsey_input($input['include_core'] ?? true);
    $include_third_party = !openmira_falsey_input($input['include_third_party'] ?? true);
    $limit = openmira_normalize_limit($input['limit'] ?? 25);

    $names = [];
    foreach ($registry->get_all_registered() as $block_type) {
        if (!openmira_block_type_matches_filters(
            $block_type,
            $search,
            $namespace,
            $include_core,
            $include_third_party,
        )) {
            continue;
        }
        $names[] = $block_type->name;
        if (count($names) >= $limit) {
            break;
        }
    }

    return $names;
}

/**
 * Return whether a block type passes list filters.
 */
function openmira_block_type_matches_filters(
    WP_Block_Type $block_type,
    string $search,
    string $namespace,
    bool $include_core,
    bool $include_third_party,
): bool {
    $name = $block_type->name;
    $is_core = str_starts_with($name, 'core/');
    if ($is_core && !$include_core || !$is_core && !$include_third_party) {
        return false;
    }
    if ($namespace !== '' && !str_starts_with($name, $namespace . '/')) {
        return false;
    }
    if ($search === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', [
        $name,
        $block_type->title,
        (string) $block_type->category,
        $block_type->description,
    ]));

    return str_contains($haystack, $search);
}

/**
 * Summarize a block type for list output.
 *
 * @return array<string, mixed>
 */
function openmira_summarize_block_type(WP_Block_Type $block_type, bool $include_schema): array
{
    $summary = [
        'name' => $block_type->name,
        'title' => $block_type->title,
        'category' => $block_type->category,
        'description' => $block_type->description,
        'namespace' => openmira_block_namespace($block_type->name),
        'api_version' => $block_type->api_version,
        'is_dynamic' => $block_type->is_dynamic(),
        'parent' => is_array($block_type->parent) ? $block_type->parent : [],
        'ancestor' => is_array($block_type->ancestor) ? $block_type->ancestor : [],
        'allowed_blocks' => is_array($block_type->allowed_blocks) ? $block_type->allowed_blocks : [],
    ];
    if ($include_schema) {
        $summary['attributes'] = is_array($block_type->attributes) ? $block_type->attributes : [];
        $summary['supports'] = is_array($block_type->supports) ? $block_type->supports : [];
    }

    return $summary;
}

/**
 * Describe a block type in detail.
 *
 * @return array<string, mixed>
 */
function openmira_describe_block_type(WP_Block_Type $block_type): array
{
    return (
        openmira_summarize_block_type($block_type, include_schema: true)
        + [
            'keywords' => $block_type->keywords,
            'styles' => $block_type->styles,
            'selectors' => $block_type->selectors,
            'provides_context' => is_array($block_type->provides_context) ? $block_type->provides_context : [],
            'editor_script_handles' => $block_type->editor_script_handles,
            'script_handles' => $block_type->script_handles,
            'view_script_handles' => $block_type->view_script_handles,
            'editor_style_handles' => $block_type->editor_style_handles,
            'style_handles' => $block_type->style_handles,
            'view_style_handles' => $block_type->view_style_handles,
        ]
    );
}

/**
 * Recursively validate parsed blocks.
 *
 * @param list<array<array-key, mixed>>  $blocks
 * @param list<string>                   $ancestor_names
 * @param list<string>                   $path
 * @param list<array<string, mixed>>     $errors
 * @param list<array<string, mixed>>     $warnings
 */
function openmira_validate_parsed_blocks(
    array $blocks,
    array $ancestor_names,
    array $path,
    array &$errors,
    array &$warnings,
): void {
    $registry = WP_Block_Type_Registry::get_instance();
    // @mago-expect analysis:mixed-assignment
    foreach ($blocks as $index => $block) {
        $block_path = [...$path, (string) $index];
        // @mago-expect analysis:mixed-assignment
        $block_name = $block['blockName'] ?? null;
        $inner_html = is_string($block['innerHTML'] ?? null) ? trim($block['innerHTML']) : '';
        if ($block_name === null) {
            if ($inner_html !== '') {
                $warnings[] = openmira_block_validation_issue(
                    code: 'classic_html',
                    path: $block_path,
                    block_name: '',
                    message: 'Classic/freeform HTML is present.',
                );
            }
            continue;
        }
        if (!is_string($block_name)) {
            $errors[] = openmira_block_validation_issue(
                code: 'invalid_block_name',
                path: $block_path,
                block_name: '',
                message: 'Block name is invalid.',
            );
            continue;
        }

        $block_type = $registry->get_registered($block_name);
        if (!$block_type instanceof WP_Block_Type) {
            $errors[] = openmira_block_validation_issue(
                code: 'unknown_block_type',
                path: $block_path,
                block_name: $block_name,
                message: 'Block type is not registered.',
            );
        }
        if ($block_type instanceof WP_Block_Type) {
            openmira_validate_block_relationships($block_type, $ancestor_names, $block_path, $errors);
            openmira_validate_block_attrs($block, $block_type, $block_path, $warnings);
        }

        $inner_blocks = openmira_normalize_parsed_blocks($block['innerBlocks'] ?? []);
        openmira_validate_parsed_blocks(
            $inner_blocks,
            [...$ancestor_names, $block_name],
            $block_path,
            $errors,
            $warnings,
        );
    }
}

/**
 * Validate parent and ancestor relationships.
 *
 * @param list<string>               $ancestor_names
 * @param list<string>               $path
 * @param list<array<string, mixed>> $errors
 */
function openmira_validate_block_relationships(
    WP_Block_Type $block_type,
    array $ancestor_names,
    array $path,
    array &$errors,
): void {
    $parent_name = $ancestor_names === [] ? '' : $ancestor_names[array_key_last($ancestor_names)];
    if (
        is_array($block_type->parent)
        && $block_type->parent !== []
        && !in_array($parent_name, $block_type->parent, strict: true)
    ) {
        $errors[] = openmira_block_validation_issue(
            code: 'invalid_parent',
            path: $path,
            block_name: $block_type->name,
            message: 'Block requires a specific direct parent.',
            extra: ['allowed_parents' => $block_type->parent, 'actual_parent' => $parent_name],
        );
    }
    if (
        is_array($block_type->ancestor)
        && $block_type->ancestor !== []
        && array_intersect($ancestor_names, $block_type->ancestor) === []
    ) {
        $errors[] = openmira_block_validation_issue(
            code: 'invalid_ancestor',
            path: $path,
            block_name: $block_type->name,
            message: 'Block requires a specific ancestor.',
            extra: ['allowed_ancestors' => $block_type->ancestor, 'actual_ancestors' => $ancestor_names],
        );
    }
    if ($parent_name !== '') {
        $parent_type = WP_Block_Type_Registry::get_instance()->get_registered($parent_name);
        if (
            $parent_type instanceof WP_Block_Type
            && is_array($parent_type->allowed_blocks)
            && $parent_type->allowed_blocks !== []
            && !in_array($block_type->name, $parent_type->allowed_blocks, strict: true)
        ) {
            $errors[] = openmira_block_validation_issue(
                code: 'disallowed_child',
                path: $path,
                block_name: $block_type->name,
                message: 'Parent block does not allow this child block type.',
                extra: ['parent' => $parent_name, 'allowed_blocks' => $parent_type->allowed_blocks],
            );
        }
    }
}

/**
 * Validate comment JSON attrs against registered attr names.
 *
 * @param array<array-key, mixed>    $block
 * @param list<string>               $path
 * @param list<array<string, mixed>> $warnings
 */
function openmira_validate_block_attrs(array $block, WP_Block_Type $block_type, array $path, array &$warnings): void
{
    $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
    if ($attrs === [] || !is_array($block_type->attributes)) {
        return;
    }

    $known_attrs = array_merge(array_keys($block_type->attributes), ['lock', 'metadata']);
    $unknown_attrs = array_values(array_diff(array_keys($attrs), $known_attrs));
    if ($unknown_attrs !== []) {
        $warnings[] = openmira_block_validation_issue(
            code: 'unknown_attrs',
            path: $path,
            block_name: $block_type->name,
            message: 'Block comment contains attrs not present in the server-registered attribute schema.',
            extra: ['unknown_attrs' => $unknown_attrs],
        );
    }
}

/**
 * Build a validation issue object.
 *
 * @param list<string> $path
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function openmira_block_validation_issue(
    string $code,
    array $path,
    string $block_name,
    string $message,
    array $extra = [],
): array {
    return [
        'code' => $code,
        'path' => $path,
        'block_name' => $block_name,
        'message' => $message,
    ] + $extra;
}

/**
 * Count named blocks recursively.
 *
 * @param list<array<array-key, mixed>> $blocks
 */
function openmira_count_named_blocks(array $blocks): int
{
    $count = 0;
    foreach ($blocks as $block) {
        if (is_string($block['blockName'] ?? null)) {
            $count++;
        }
        $count += openmira_count_named_blocks(openmira_normalize_parsed_blocks($block['innerBlocks'] ?? []));
    }

    return $count;
}

/**
 * Normalize parsed block values into list-shaped parsed block arrays.
 *
 * @return list<array<array-key, mixed>>
 */
function openmira_normalize_parsed_blocks(mixed $blocks): array
{
    if (!is_array($blocks)) {
        return [];
    }

    $normalized = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $normalized[] = $block;
    }

    return $normalized;
}

/**
 * Return block namespace prefix.
 */
function openmira_block_namespace(string $block_name): string
{
    $parts = explode('/', $block_name, limit: 2);
    return $parts[0];
}

/**
 * Explain the server/editor validation boundary.
 */
function openmira_block_serialization_note(): string
{
    return 'WordPress PHP exposes registered block metadata and can parse structural relationships, but static block saved HTML is produced by each block editor JavaScript save() implementation. For exact core or third-party markup, use editor-side wp.blocks.createBlock/wp.blocks.serialize or paste through the editor and validate there.';
}
