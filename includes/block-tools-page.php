<?php

declare(strict_types=1);

/**
 * Browser-backed Gutenberg block tools.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render the block tools page.
 */
function novamira_render_block_tools_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    novamira_enqueue_block_tools_assets();

    $job_id = sanitize_key(novamira_block_tools_request_string($_GET['job'] ?? ''));
    $job = $job_id !== '' ? novamira_get_block_serialization_job($job_id) : null;
    $example = is_array($job) && is_array($job['spec'] ?? null)
        ? $job['spec']
        : novamira_get_block_serializer_example();
    $example_json = wp_json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $example_json = is_string($example_json) ? $example_json : '[]';
    $example_inline_json = wp_json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $example_inline_json = is_string($example_inline_json) ? $example_inline_json : '[]';
    $job_payload = [
        'jobId' => $job_id,
        'kind' => is_array($job) ? (string) ($job['kind'] ?? 'serialize') : 'serialize',
        'blockNames' => is_array($job) && is_array($job['block_names'] ?? null)
            ? array_values($job['block_names'])
            : [],
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => $job_id !== '' ? wp_create_nonce('novamira_block_serialization_job_' . $job_id) : '',
        'autoRun' => is_array($job),
    ];
    $job_payload_json = wp_json_encode($job_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $job_payload_json = is_string($job_payload_json) ? $job_payload_json : '{}';

    novamira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Block Tools', domain: 'novamira'); ?></h1>
        <p>
            <?php esc_html_e(
                'Serialize block specs with the same WordPress editor JavaScript that validates saved static block markup.',
                domain: 'novamira',
            ); ?>
        </p>
        <?php novamira_render_block_tools_job_notices($job, $job_id); ?>
        <div class="notice notice-info">
            <p>
                <?php esc_html_e(
                    'Server-side block registry abilities discover block metadata. This page handles the other half: exact editor-side save() serialization for blocks loaded in this admin screen.',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
        <p>
            <button type="button" class="button" id="novamira-refresh-block-types">
                <?php esc_html_e('Refresh Loaded Block Types', domain: 'novamira'); ?>
            </button>
            <span id="novamira-loaded-block-summary" style="margin-left:8px;"></span>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="novamira-block-spec"><?php esc_html_e('Block Spec JSON', domain: 'novamira'); ?></label>
                </th>
                <td>
                    <textarea id="novamira-block-spec" class="large-text code" rows="18"><?php echo
                        esc_textarea($example_json)
                    ; ?></textarea>
                    <p class="description">
                        <?php esc_html_e(
                            'Use one block object or an array of block objects. Each object supports name, attrs, and innerBlocks.',
                            domain: 'novamira',
                        ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
                <td>
                    <button type="button" class="button button-primary" id="novamira-serialize-blocks">
                        <?php esc_html_e('Serialize With Editor JS', domain: 'novamira'); ?>
                    </button>
                    <button type="button" class="button" id="novamira-load-example">
                        <?php esc_html_e('Reload Example', domain: 'novamira'); ?>
                    </button>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="novamira-serialized-output"><?php esc_html_e(
                        'Serialized Markup',
                        domain: 'novamira',
                    ); ?></label>
                </th>
                <td>
                    <textarea id="novamira-serialized-output" class="large-text code" rows="16" readonly></textarea>
                    <p class="description" id="novamira-serializer-status"></p>
                </td>
            </tr>
        </table>
    </div>
    <script type="application/json" id="novamira-block-tools-example"><?php echo $example_inline_json; ?></script>
    <script type="application/json" id="novamira-block-tools-job"><?php echo $job_payload_json; ?></script>
    <?php
}

/**
 * Render serializer/profile job notices.
 *
 * @param array<array-key, mixed>|null $job
 */
function novamira_render_block_tools_job_notices(?array $job, string $job_id): void
{
    if (is_array($job)) {
        ?>
        <div class="notice notice-success">
            <p><?php esc_html_e(
                'Block tools job loaded. This page will run it automatically and save the result for MCP.',
                domain: 'novamira',
            ); ?></p>
        </div>
        <?php

        return;
    }

    if ($job_id === '') {
        return;
    }
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Block tools job not found or expired.', domain: 'novamira'); ?></p>
    </div>
    <?php
}

/**
 * Enqueue editor-side block registration assets for serializer jobs.
 */
function novamira_enqueue_block_tools_assets(): void
{
    wp_enqueue_script('wp-blocks');
    wp_enqueue_script('wp-block-library');
    wp_enqueue_script('wp-block-editor');
    wp_enqueue_script('wp-data');
    wp_enqueue_script('wp-element');
    wp_enqueue_script('wp-hooks');

    $load_editor_assets = static fn(): bool => true;
    add_filter('should_load_block_editor_scripts_and_styles', $load_editor_assets);
    wp_enqueue_registered_block_scripts_and_styles();
    do_action('enqueue_block_editor_assets');
    remove_filter('should_load_block_editor_scripts_and_styles', $load_editor_assets);

    wp_add_inline_script('wp-blocks', novamira_get_block_tools_script(), position: 'after');
}

add_action('wp_ajax_novamira_save_block_serialization_job', callback: 'novamira_ajax_save_block_serialization_job');

/**
 * Save a browser-completed serialization job.
 */
function novamira_ajax_save_block_serialization_job(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions.'], status_code: 403);
    }

    $job_id = sanitize_key(novamira_block_tools_request_string($_POST['job_id'] ?? ''));
    if ($job_id === '') {
        wp_send_json_error(['message' => 'Missing job_id.'], status_code: 400);
    }
    check_ajax_referer('novamira_block_serialization_job_' . $job_id, query_arg: 'nonce');

    $status = sanitize_key(novamira_block_tools_request_string($_POST['status'] ?? ''));
    $markup = wp_unslash(novamira_block_tools_request_string($_POST['markup'] ?? ''));
    $error = sanitize_textarea_field(wp_unslash(novamira_block_tools_request_string($_POST['error'] ?? '')));
    $loaded_block_count = max(0, (int) ($_POST['loaded_block_count'] ?? 0));
    $top_level_block_count = max(0, (int) ($_POST['top_level_block_count'] ?? 0));
    $profiles = novamira_decode_block_profiles_payload(wp_unslash(novamira_block_tools_request_string(
        $_POST['profiles'] ?? '',
    )));
    if (is_wp_error($profiles)) {
        wp_send_json_error(['message' => $profiles->get_error_message()], status_code: 400);
    }

    $updates = [
        'status' => $status === 'failed' ? 'failed' : 'complete',
        'markup' => $markup,
        'error' => $error,
        'loaded_block_count' => $loaded_block_count,
        'top_level_block_count' => $top_level_block_count,
        'completed_at' => time(),
    ];
    if ($profiles !== []) {
        $updates['profiles'] = $profiles;
        novamira_save_gutenberg_block_profiles($profiles);
    }

    $result = novamira_update_block_serialization_job($job_id, $updates);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], status_code: 404);
    }

    wp_send_json_success(['job' => $result]);
}

/**
 * Create a pending editor-side Gutenberg serialization job.
 *
 * @param array<array-key, mixed> $spec
 * @return array<string, mixed>|WP_Error
 */
function novamira_create_block_serialization_job(array $spec): array|WP_Error
{
    $encoded_spec = wp_json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded_spec)) {
        return new WP_Error('invalid_block_spec', 'Block spec could not be encoded as JSON.');
    }
    if (strlen($encoded_spec) > 200_000) {
        return new WP_Error('block_spec_too_large', 'Block spec is too large for an editor serialization job.');
    }

    // @mago-expect analysis:mixed-assignment
    $uuid = wp_generate_uuid4();
    $job_id = is_string($uuid)
        ? str_replace(search: '-', replace: '', subject: $uuid)
        : hash('sha256', uniqid('novamira', more_entropy: true));
    $job = [
        'job_id' => $job_id,
        'kind' => 'serialize',
        'status' => 'pending',
        'spec' => $spec,
        'spec_hash' => hash('sha256', $encoded_spec),
        'markup' => '',
        'error' => '',
        'loaded_block_count' => 0,
        'top_level_block_count' => 0,
        'created_at' => time(),
        'completed_at' => 0,
    ];

    $jobs = novamira_get_block_serialization_jobs();
    $jobs[$job_id] = $job;
    novamira_save_block_serialization_jobs($jobs);

    return $job
    + [
        'serializer_url' => novamira_get_block_serialization_job_url($job_id),
    ];
}

/**
 * Create a pending editor-side Gutenberg block profile job.
 *
 * @param list<string> $block_names
 * @return array<string, mixed>|WP_Error
 */
function novamira_create_block_profile_job(array $block_names): array|WP_Error
{
    $block_names = array_values(array_unique(array_filter($block_names, callback: 'is_string')));
    if ($block_names === []) {
        return new WP_Error('missing_block_names', 'Provide at least one block name to profile.');
    }
    if (count($block_names) > 100) {
        return new WP_Error('too_many_block_names', 'Profile jobs are limited to 100 block types.');
    }

    $encoded_names = wp_json_encode($block_names, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded_names)) {
        return new WP_Error('invalid_block_names', 'Block names could not be encoded as JSON.');
    }

    // @mago-expect analysis:mixed-assignment
    $uuid = wp_generate_uuid4();
    $job_id = is_string($uuid)
        ? str_replace(search: '-', replace: '', subject: $uuid)
        : hash('sha256', uniqid('novamira_profile', more_entropy: true));
    $job = [
        'job_id' => $job_id,
        'kind' => 'profile',
        'status' => 'pending',
        'spec' => [],
        'block_names' => $block_names,
        'profiles' => [],
        'spec_hash' => hash('sha256', $encoded_names),
        'markup' => '',
        'error' => '',
        'loaded_block_count' => 0,
        'top_level_block_count' => count($block_names),
        'created_at' => time(),
        'completed_at' => 0,
    ];

    $jobs = novamira_get_block_serialization_jobs();
    $jobs[$job_id] = $job;
    novamira_save_block_serialization_jobs($jobs);

    return $job
    + [
        'serializer_url' => novamira_get_block_serialization_job_url($job_id),
    ];
}

/**
 * Read a serialization job.
 *
 * @return array<array-key, mixed>|null
 */
function novamira_get_block_serialization_job(string $job_id): ?array
{
    $jobs = novamira_get_block_serialization_jobs();
    $job = $jobs[$job_id] ?? null;
    return is_array($job) ? $job : null;
}

/**
 * Update a serialization job.
 *
 * @param array<string, mixed> $updates
 * @return array<array-key, mixed>|WP_Error
 */
function novamira_update_block_serialization_job(string $job_id, array $updates): array|WP_Error
{
    $jobs = novamira_get_block_serialization_jobs();
    if (!is_array($jobs[$job_id] ?? null)) {
        return new WP_Error('serialization_job_not_found', 'Serialization job not found.');
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($updates as $key => $value) {
        $jobs[$job_id][$key] = $value;
    }
    novamira_save_block_serialization_jobs($jobs);

    return $jobs[$job_id];
}

/**
 * Return the admin URL that executes a serialization job.
 */
function novamira_get_block_serialization_job_url(string $job_id): string
{
    return admin_url('admin.php?page=novamira-block-tools&job=' . rawurlencode($job_id));
}

/**
 * Return stored serialization jobs.
 *
 * @return array<string, array<array-key, mixed>>
 */
function novamira_get_block_serialization_jobs(): array
{
    // @mago-expect analysis:mixed-assignment
    $jobs = get_option('novamira_block_serialization_jobs', default_value: []);
    if (!is_array($jobs)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($jobs as $job_id => $job) {
        if (!is_string($job_id) || !is_array($job)) {
            continue;
        }
        $normalized[$job_id] = $job;
    }

    return $normalized;
}

/**
 * Save serialization jobs after pruning old entries.
 *
 * @param array<string, array<array-key, mixed>> $jobs
 */
function novamira_save_block_serialization_jobs(array $jobs): void
{
    $cutoff = time() - 86_400;
    foreach ($jobs as $job_id => $job) {
        $created_at = (int) ($job['created_at'] ?? 0);
        if ($created_at > 0 && $created_at >= $cutoff) {
            continue;
        }
        unset($jobs[$job_id]);
    }
    if (count($jobs) > 25) {
        uasort(
            $jobs,
            static fn(array $a, array $b): int => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0),
        );
        $jobs = array_slice($jobs, offset: 0, length: 25, preserve_keys: true);
    }

    update_option('novamira_block_serialization_jobs', $jobs, autoload: false);
}

/**
 * Return cached Gutenberg block profiles.
 *
 * @return array<string, array<array-key, mixed>>
 */
function novamira_get_gutenberg_block_profiles(): array
{
    // @mago-expect analysis:mixed-assignment
    $profiles = get_option('novamira_gutenberg_block_profiles', default_value: []);
    if (!is_array($profiles)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($profiles as $name => $profile) {
        if (!is_string($name) || !is_array($profile)) {
            continue;
        }
        $normalized[$name] = $profile;
    }

    return $normalized;
}

/**
 * Merge browser-generated Gutenberg block profiles into the cache.
 *
 * @param list<array<array-key, mixed>> $profiles
 */
function novamira_save_gutenberg_block_profiles(array $profiles): void
{
    $cached = novamira_get_gutenberg_block_profiles();
    foreach ($profiles as $profile) {
        $name = is_string($profile['name'] ?? null) ? $profile['name'] : '';
        if ($name === '') {
            continue;
        }
        $profile['profiled_at'] = time();
        $cached[$name] = $profile;
    }

    ksort($cached);
    update_option('novamira_gutenberg_block_profiles', $cached, autoload: false);
}

/**
 * Decode the browser profile payload.
 *
 * @return list<array<array-key, mixed>>|WP_Error
 */
function novamira_decode_block_profiles_payload(string $payload): array|WP_Error
{
    if ($payload === '') {
        return [];
    }

    // @mago-expect analysis:mixed-assignment
    $decoded = json_decode($payload, associative: true);
    if (!is_array($decoded)) {
        return new WP_Error('invalid_block_profiles', 'Profiles payload must be a JSON array.');
    }

    $profiles = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($decoded as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $profiles[] = $profile;
    }

    return $profiles;
}

/**
 * Return a scalar request value as a string.
 */
function novamira_block_tools_request_string(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/**
 * Return a nested core block example.
 *
 * @return list<array<string, mixed>>
 */
function novamira_get_block_serializer_example(): array
{
    return [
        [
            'name' => 'core/group',
            'attrs' => [
                'layout' => ['type' => 'constrained'],
                'style' => [
                    'spacing' => [
                        'padding' => [
                            'top' => 'var:preset|spacing|60',
                            'bottom' => 'var:preset|spacing|60',
                        ],
                    ],
                ],
            ],
            'innerBlocks' => [
                [
                    'name' => 'core/heading',
                    'attrs' => ['level' => 2, 'content' => 'Editor-serialized section'],
                ],
                [
                    'name' => 'core/paragraph',
                    'attrs' => [
                        'content' => 'This markup is produced by wp.blocks.serialize(), not hand-written PHP.',
                    ],
                ],
                [
                    'name' => 'core/buttons',
                    'attrs' => ['layout' => ['type' => 'flex', 'justifyContent' => 'left']],
                    'innerBlocks' => [
                        [
                            'name' => 'core/button',
                            'attrs' => ['text' => 'Primary action', 'url' => '#primary'],
                        ],
                        [
                            'name' => 'core/button',
                            'attrs' => [
                                'text' => 'Secondary action',
                                'url' => '#secondary',
                                'className' => 'is-style-outline',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'core/columns',
                    'attrs' => [],
                    'innerBlocks' => [
                        [
                            'name' => 'core/column',
                            'attrs' => [],
                            'innerBlocks' => [
                                ['name' => 'core/heading', 'attrs' => ['level' => 3, 'content' => 'One']],
                                ['name' => 'core/paragraph', 'attrs' => ['content' => 'First column.']],
                            ],
                        ],
                        [
                            'name' => 'core/column',
                            'attrs' => [],
                            'innerBlocks' => [
                                ['name' => 'core/heading', 'attrs' => ['level' => 3, 'content' => 'Two']],
                                ['name' => 'core/paragraph', 'attrs' => ['content' => 'Second column.']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Return the block tools browser script.
 */
function novamira_get_block_tools_script(): string
{
    return <<<'JS'
        document.addEventListener('DOMContentLoaded', function () {
            const specInput = document.getElementById('novamira-block-spec');
            const output = document.getElementById('novamira-serialized-output');
            const status = document.getElementById('novamira-serializer-status');
            const summary = document.getElementById('novamira-loaded-block-summary');
            const exampleNode = document.getElementById('novamira-block-tools-example');
            const jobNode = document.getElementById('novamira-block-tools-job');

            function setStatus(message, isError) {
                status.textContent = message;
                status.style.color = isError ? '#b32d2e' : '#1d2327';
            }

            function getExample() {
                return JSON.parse(exampleNode.textContent || '[]');
            }

            function getJob() {
                return JSON.parse(jobNode.textContent || '{}');
            }

            function ensureCoreBlocksRegistered() {
                if (wp.blocks.getBlockType('core/group')) {
                    return;
                }
                if (wp.blockLibrary && typeof wp.blockLibrary.registerCoreBlocks === 'function') {
                    wp.blockLibrary.registerCoreBlocks();
                }
            }

            function buildBlock(spec) {
                if (!spec || typeof spec !== 'object') {
                    throw new Error('Each block spec must be an object.');
                }
                if (!spec.name || typeof spec.name !== 'string') {
                    throw new Error('Each block spec requires a string name.');
                }
                if (!wp.blocks.getBlockType(spec.name)) {
                    throw new Error('Block type is not loaded in this admin screen: ' + spec.name);
                }
                const innerBlocks = Array.isArray(spec.innerBlocks) ? spec.innerBlocks.map(buildBlock) : [];
                return wp.blocks.createBlock(spec.name, spec.attrs || {}, innerBlocks);
            }

            function refreshLoadedBlocks() {
                ensureCoreBlocksRegistered();
                const blockTypes = wp.blocks.getBlockTypes();
                summary.textContent = blockTypes.length + ' block types loaded in this screen.';
                return blockTypes.length;
            }

            function serializeCurrentSpec() {
                ensureCoreBlocksRegistered();
                const parsed = JSON.parse(specInput.value);
                const specs = Array.isArray(parsed) ? parsed : [parsed];
                const blocks = specs.map(buildBlock);
                return {
                    markup: wp.blocks.serialize(blocks),
                    topLevelBlockCount: blocks.length,
                    loadedBlockCount: wp.blocks.getBlockTypes().length,
                };
            }

            function exampleToSpec(name, example) {
                const attrs = example && typeof example === 'object' && example.attributes
                    ? example.attributes
                    : {};
                const innerBlocks = example && typeof example === 'object' && Array.isArray(example.innerBlocks)
                    ? example.innerBlocks.map(function (inner) {
                        return exampleToSpec(inner.name, {
                            attributes: inner.attributes || {},
                            innerBlocks: inner.innerBlocks || [],
                        });
                    })
                    : [];

                return {name, attrs, innerBlocks};
            }

            function summarizeIssues(blocks) {
                const issues = [];
                function collect(items, path) {
                    items.forEach(function (block, index) {
                        const blockPath = path.concat([String(index)]);
                        if (block && block.isValid === false) {
                            issues.push({
                                path: blockPath.join('.'),
                                name: block.name || '',
                                issues: Array.isArray(block.validationIssues)
                                    ? block.validationIssues.map(function (issue) {
                                        return issue && issue.args && issue.args[0] ? String(issue.args[0]) : String(issue);
                                    })
                                    : [],
                            });
                        }
                        collect(block.innerBlocks || [], blockPath);
                    });
                }
                collect(blocks || [], []);
                return issues;
            }

            function profileCandidate(candidate) {
                const block = buildBlock(candidate.spec);
                const markup = wp.blocks.serialize([block]);
                const parsed = typeof wp.blocks.parse === 'function' ? wp.blocks.parse(markup) : [];
                const issues = summarizeIssues(parsed);

                return {
                    source: candidate.source,
                    spec: candidate.spec,
                    ok: issues.length === 0,
                    markupLength: markup.length,
                    issues,
                };
            }

            function profileBlock(name) {
                const blockType = wp.blocks.getBlockType(name);
                if (!blockType) {
                    return {
                        name,
                        loaded: false,
                        safe: false,
                        classification: 'not_loaded',
                        error: 'Block type is not loaded in this admin screen.',
                    };
                }

                const candidates = [];
                if (blockType.example) {
                    candidates.push({source: 'example', spec: exampleToSpec(name, blockType.example)});
                }
                candidates.push({source: 'empty', spec: {name, attrs: {}, innerBlocks: []}});

                const attempts = candidates.map(function (candidate) {
                    try {
                        return profileCandidate(candidate);
                    } catch (error) {
                        return {
                            source: candidate.source,
                            ok: false,
                            markupLength: 0,
                            issues: [],
                            error: error instanceof Error ? error.message : String(error),
                        };
                    }
                });
                const validAttempt = attempts.find(function (attempt) {
                    return attempt.ok;
                });
                const sourcedAttrKeys = Object.entries(blockType.attributes || {})
                    .filter(function (entry) {
                        const definition = entry[1] || {};
                        return Boolean(definition.source || definition.selector || definition.attribute);
                    })
                    .map(function (entry) {
                        return entry[0];
                    });
                const generationQuality = getGenerationQuality(validAttempt, blockType.example, sourcedAttrKeys);

                return {
                    name,
                    title: blockType.title || '',
                    category: blockType.category || '',
                    namespace: name.includes('/') ? name.split('/')[0] : '',
                    loaded: true,
                    safe: Boolean(validAttempt),
                    classification: validAttempt ? 'safe_' + validAttempt.source : 'unsafe_serialization',
                    generation_quality: generationQuality.quality,
                    generation_note: generationQuality.note,
                    adapter_recommended: generationQuality.adapterRecommended,
                    safe_source: validAttempt ? validAttempt.source : '',
                    safe_spec: validAttempt ? validAttempt.spec : null,
                    has_example: Boolean(blockType.example),
                    custom_attrs_risky: sourcedAttrKeys.length > 0,
                    sourced_attr_keys: sourcedAttrKeys,
                    parent: Array.isArray(blockType.parent) ? blockType.parent : [],
                    ancestor: Array.isArray(blockType.ancestor) ? blockType.ancestor : [],
                    allowed_blocks: Array.isArray(blockType.allowedBlocks) ? blockType.allowedBlocks : [],
                    attr_keys: Object.keys(blockType.attributes || {}),
                    save_type: typeof blockType.save,
                    attempts,
                };
            }

            function getGenerationQuality(validAttempt, example, sourcedAttrKeys) {
                if (!validAttempt) {
                    return {
                        quality: 'unsafe',
                        adapterRecommended: true,
                        note: 'No candidate serialized and parsed cleanly.',
                    };
                }
                if (validAttempt.source === 'empty' && sourcedAttrKeys.length > 0) {
                    return {
                        quality: 'low',
                        adapterRecommended: true,
                        note: 'Empty serialization is valid, but meaningful content lives in sourced attributes; use a block-specific recipe before generating custom content.',
                    };
                }
                if (!example && validAttempt.markupLength < 120) {
                    return {
                        quality: 'low',
                        adapterRecommended: true,
                        note: 'The block validates, but the safe output is nearly empty; use as a container primitive or add a recipe.',
                    };
                }
                if (sourcedAttrKeys.length > 0) {
                    return {
                        quality: 'medium',
                        adapterRecommended: true,
                        note: 'The block has sourced saved-markup attributes; generated custom attrs should be recipe-backed and revalidated.',
                    };
                }

                return {
                    quality: 'high',
                    adapterRecommended: false,
                    note: 'The block has a safe candidate without sourced saved-markup attributes.',
                };
            }

            function runBlockProfiles(blockNames) {
                ensureCoreBlocksRegistered();
                const profiles = blockNames.map(profileBlock);
                return {
                    profiles,
                    loadedBlockCount: wp.blocks.getBlockTypes().length,
                    topLevelBlockCount: profiles.length,
                    safeCount: profiles.filter(function (profile) {
                        return profile.safe;
                    }).length,
                };
            }

            async function saveJobResult(job, result, error) {
                if (!job.jobId || !job.ajaxUrl || !job.nonce) {
                    return;
                }

                const body = new URLSearchParams();
                body.set('action', 'novamira_save_block_serialization_job');
                body.set('job_id', job.jobId);
                body.set('nonce', job.nonce);
                body.set('status', error ? 'failed' : 'complete');
                body.set('markup', result ? result.markup : '');
                body.set('error', error || '');
                body.set('loaded_block_count', String(result ? result.loadedBlockCount : wp.blocks.getBlockTypes().length));
                body.set('top_level_block_count', String(result ? result.topLevelBlockCount : 0));
                if (result && Array.isArray(result.profiles)) {
                    body.set('profiles', JSON.stringify(result.profiles));
                }

                const response = await fetch(job.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body,
                });
                if (!response.ok) {
                    throw new Error('Could not save serialization job result.');
                }
            }

            async function runProfiling(shouldSaveJob) {
                const job = getJob();
                try {
                    const result = runBlockProfiles(Array.isArray(job.blockNames) ? job.blockNames : []);
                    output.value = JSON.stringify(result.profiles, null, 2);
                    setStatus(
                        'Profiled ' + result.topLevelBlockCount + ' block type(s); ' + result.safeCount + ' classified safe.',
                        false,
                    );
                    if (shouldSaveJob) {
                        await saveJobResult(job, result, '');
                        setStatus(
                            'Profiled ' + result.topLevelBlockCount + ' block type(s); ' + result.safeCount + ' classified safe and cached.',
                            false,
                        );
                    }
                } catch (error) {
                    const message = error instanceof Error ? error.message : String(error);
                    output.value = '';
                    setStatus(message, true);
                    if (shouldSaveJob) {
                        await saveJobResult(job, null, message);
                    }
                }
            }

            async function runSerialization(shouldSaveJob) {
                const job = getJob();
                try {
                    const result = serializeCurrentSpec();
                    output.value = result.markup;
                    setStatus('Serialized ' + result.topLevelBlockCount + ' top-level block(s).', false);
                    if (shouldSaveJob) {
                        await saveJobResult(job, result, '');
                        setStatus('Serialized ' + result.topLevelBlockCount + ' top-level block(s) and saved job result.', false);
                    }
                } catch (error) {
                    const message = error instanceof Error ? error.message : String(error);
                    output.value = '';
                    setStatus(message, true);
                    if (shouldSaveJob) {
                        await saveJobResult(job, null, message);
                    }
                }
            }

            document.getElementById('novamira-load-example').addEventListener('click', function () {
                specInput.value = JSON.stringify(getExample(), null, 2);
                output.value = '';
                setStatus('Example restored.', false);
            });

            document.getElementById('novamira-refresh-block-types').addEventListener('click', refreshLoadedBlocks);

            document.getElementById('novamira-serialize-blocks').addEventListener('click', function () {
                runSerialization(false);
            });

            refreshLoadedBlocks();
            if (getJob().autoRun) {
                if (getJob().kind === 'profile') {
                    runProfiling(true);
                } else {
                    runSerialization(true);
                }
            }
        });
        JS;
}
