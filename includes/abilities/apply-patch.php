<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: WordPress-aware patch grammar.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/apply-patch', [
    'label' => __('Apply WP Patch', domain: 'open-mira'),
    'description' => __(
        'Applies Open Mira WP-aware patches. For theme.json design systems, prefer one bulk hunk: *** Update theme.json (paths, mode: merge): with an object keyed by paths like settings.color.palette and styles.elements.button.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'patch' => [
                'type' => 'string',
                'description' => 'Patch text wrapped in *** Begin Patch / *** End Patch. For theme.json, use one bulk *** Update theme.json (paths, mode: merge): hunk with a JSON object keyed by paths. Use single-path hunks only for tiny surgical edits.',
                'minLength' => 1,
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Preview the resulting diff without writing.',
                'default' => false,
            ],
            'include_diff' => [
                'type' => 'boolean',
                'description' => 'Include the unified diff in the response. Defaults to true; set false for large successful patches.',
                'default' => true,
            ],
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug to patch. Defaults to the active stylesheet theme.',
                'default' => '',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Optional theme.json path. Use this when the file path is known; it bypasses stale theme-registry lookups while keeping stale-write protection.',
                'default' => '',
            ],
            'expected_current_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash for theme.json. If it matches the current file, this can replace an explicit read-file call.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID for block patch hunks such as *** Update Block (ref: ...):.',
                'minimum' => 1,
            ],
            'expected_etag' => [
                'type' => 'string',
                'description' => 'Optional ETag from read-blocks for block patch hunks. Required when using virtual refs.',
            ],
        ],
        'required' => ['patch'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'theme' => ['type' => 'object'],
            'dry_run' => ['type' => 'boolean'],
            'operations' => ['type' => 'array', 'items' => ['type' => 'object']],
            'previous_hash' => ['type' => 'string'],
            'content_hash' => ['type' => 'string'],
            'diff_summary' => ['type' => 'string'],
            'diff' => ['type' => 'string'],
            'backup' => ['type' => 'object'],
            'audit' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'openmira_apply_patch',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use for semantic WordPress edits instead of hand-editing files.',
                'Patch text must include the envelope:',
                '*** Begin Patch',
                '*** Update theme.json (path: settings.color.palette):',
                '[JSON value]',
                '*** Update theme.json (paths, mode: merge):',
                '{"settings.typography.fontFamilies": [ ... ], "styles.elements.button": { ... }}',
                '*** Update Block (ref: omr_...):',
                '{"attrs": {"heading": "Updated"}, "attrs_mode": "merge"}',
                '*** End Patch',
                'Prefer the paths bulk form for landing-page/theme design-system setup: palette, typography, spacing, layout, and element styles in one call.',
                'For post content, use read-blocks first, then block hunks with post_id and expected_etag.',
                'Use single path: only for tiny surgical edits. Use write-file only when replacing the whole file is truly intended.',
                'Use mode: merge when updating an object without replacing sibling keys.',
                'Set include_diff=false for large successful patches when a summary is enough.',
                'Read theme.json first, or pass expected_current_hash from a recent scaffold/read response; stale-write protection is enforced.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Apply Open Mira WP-aware patch grammar.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
// @mago-expect lint:kan-defect
function openmira_apply_patch(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/apply-patch');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $patch = (string) ($input['patch'] ?? '');
    $dry_run = ($input['dry_run'] ?? false) === true;
    $include_diff = !openmira_patch_falsey_input($input['include_diff'] ?? true);
    $theme_slug = sanitize_key((string) ($input['theme_slug'] ?? ''));
    $expected_current_hash = (string) ($input['expected_current_hash'] ?? '');
    $path = (string) ($input['path'] ?? '');

    $operations = openmira_parse_wp_patch($patch);
    if (is_wp_error($operations)) {
        return $operations;
    }
    if (openmira_wp_patch_has_block_operations($operations)) {
        return openmira_apply_block_patch_operations($operations, $input, $dry_run);
    }

    $theme = $path !== ''
        ? openmira_patch_resolve_theme_json_path($path, $theme_slug)
        : openmira_patch_resolve_theme($theme_slug);
    if (is_wp_error($theme)) {
        return $theme;
    }

    $theme_json_path = $theme['theme_json_path'] ?? trailingslashit($theme['directory']) . 'theme.json';
    $resolved = openmira_resolve_path($theme_json_path, must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $created = !is_file($resolved);
    if (!$created) {
        $fresh_read = openmira_require_fresh_file_read(
            resolved: $resolved,
            ability: 'openmira/apply-patch',
            expected_hash: $expected_current_hash,
        );
        if (is_wp_error($fresh_read)) {
            return $fresh_read;
        }
    }

    $old_content = is_file($resolved) ? file_get_contents($resolved) : null;
    if ($old_content === false) {
        return new WP_Error('read_failed', sprintf('Could not read theme.json before patch: %s', $resolved));
    }

    $json = openmira_decode_theme_json($old_content);
    if (is_wp_error($json)) {
        return $json;
    }

    $applied = [];
    foreach ($operations as $operation) {
        $result = openmira_apply_theme_json_operation($json, $operation);
        if (is_wp_error($result)) {
            return $result;
        }
        $applied[] = $result;
    }

    $validation = openmira_validate_theme_json_data($json);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $new_content = openmira_encode_theme_json($json);
    if (is_wp_error($new_content)) {
        return $new_content;
    }

    $previous_hash = is_string($old_content) ? openmira_file_hash_content($old_content) : '';
    $content_hash = openmira_file_hash_content($new_content);
    $diff = openmira_build_unified_diff($old_content, $new_content, $resolved);
    $diff_summary = openmira_diff_summary($diff);
    $result = [
        'path' => $resolved,
        'theme' => [
            'slug' => $theme['slug'],
            'name' => $theme['name'],
            'registry_exists' => $theme['registry_exists'],
        ],
        'dry_run' => $dry_run,
        'operations' => $applied,
        'previous_hash' => $previous_hash,
        'content_hash' => $content_hash,
        'diff_summary' => $diff_summary,
    ];
    if ($include_diff || $dry_run) {
        $result['diff'] = $diff;
    }

    if ($dry_run) {
        return $result;
    }

    $parent_dir = dirname($resolved);
    $directories_created = openmira_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return $directories_created;
    }

    $backup = $created ? null : openmira_create_file_backup($resolved, operation: 'apply-patch:theme-json');
    $bytes_written = file_put_contents($resolved, $new_content, LOCK_EX);
    if ($bytes_written === false) {
        openmira_record_audit_event([
            'ability' => 'openmira/apply-patch',
            'operation' => 'theme-json',
            'target_path' => openmira_display_path($resolved),
            'status' => 'error',
            'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
            'error' => 'write_failed',
            'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
        ]);
        return new WP_Error('write_failed', sprintf('Failed to write theme.json: %s', $resolved));
    }

    if ($created) {
        chmod(filename: $resolved, permissions: 0644);
    }

    $audit = openmira_record_audit_event([
        'ability' => 'openmira/apply-patch',
        'operation' => 'theme-json',
        'target_path' => openmira_display_path($resolved),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => $diff_summary,
        'diff' => $diff,
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    if (is_array($backup)) {
        $result['backup'] = $backup;
    }
    $result['audit'] = $audit;
    $result['bytes_written'] = $bytes_written;
    $result['directories_created'] = $directories_created;

    openmira_mark_file_read($resolved);

    return $result;
}

/**
 * Return whether a REST/MCP input value explicitly means false.
 */
function openmira_patch_falsey_input(mixed $value): bool
{
    return $value === false || $value === 0 || $value === '0' || strtolower((string) $value) === 'false';
}

/**
 * Resolve the target theme for patch operations.
 *
 * @return array{slug: string, name: string, directory: string, registry_exists: bool, theme_json_path?: string}|WP_Error
 */
function openmira_patch_resolve_theme(string $theme_slug): array|WP_Error
{
    $slug = $theme_slug !== '' ? $theme_slug : get_stylesheet();
    $theme = wp_get_theme($slug);
    if (!$theme->exists()) {
        $directory = trailingslashit(get_theme_root()) . $slug;
        clearstatcache(clear_realpath_cache: true, filename: $directory);
        if (
            is_dir($directory)
            && (
                is_file(trailingslashit($directory) . 'theme.json')
                || is_file(trailingslashit($directory) . 'style.css')
            )
        ) {
            return [
                'slug' => $slug,
                'name' => openmira_patch_theme_name_from_directory($directory, $slug),
                'directory' => $directory,
                'registry_exists' => false,
            ];
        }

        return new WP_Error('theme_not_found', sprintf('Theme not found: %s', $slug), [
            'slug' => $slug,
            'theme_root' => get_theme_root(),
            'fallback_directory' => $directory,
            'fallback_exists' => is_dir($directory),
        ]);
    }

    return [
        'slug' => $slug,
        'name' => $theme->get('Name') !== '' ? $theme->get('Name') : $slug,
        'directory' => $theme->get_stylesheet_directory(),
        'registry_exists' => true,
    ];
}

/**
 * Resolve an explicit theme.json path for patch operations.
 *
 * @return array{slug: string, name: string, directory: string, registry_exists: bool, theme_json_path: string}|WP_Error
 */
function openmira_patch_resolve_theme_json_path(string $path, string $theme_slug): array|WP_Error
{
    $resolved = openmira_resolve_path(path: $path, must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (basename($resolved) !== 'theme.json') {
        return new WP_Error('invalid_theme_json_path', 'apply-patch path must point to a theme.json file.');
    }

    $directory = dirname($resolved);
    $slug = $theme_slug !== '' ? $theme_slug : basename($directory);
    $theme = wp_get_theme($slug);
    $registry_exists = $theme->exists();

    return [
        'slug' => $slug,
        'name' => $registry_exists && $theme->get('Name') !== ''
            ? $theme->get('Name')
            : openmira_patch_theme_name_from_directory($directory, $slug),
        'directory' => $directory,
        'registry_exists' => $registry_exists,
        'theme_json_path' => $resolved,
    ];
}

/**
 * Read a theme display name directly from style.css when registry lookup is stale.
 */
function openmira_patch_theme_name_from_directory(string $directory, string $fallback): string
{
    $style_css = trailingslashit($directory) . 'style.css';
    if (!is_file($style_css) || !is_readable($style_css)) {
        return $fallback;
    }

    $content = file_get_contents(filename: $style_css, use_include_path: false, context: null, offset: 0, length: 8192);
    if (!is_string($content)) {
        return $fallback;
    }

    $match = [];
    if (preg_match('/^[ \t*#@]*Theme Name:\s*(.+)$/mi', $content, $match)) {
        $name = trim($match[1]);
        return $name !== '' ? $name : $fallback;
    }

    return $fallback;
}

/**
 * Parse Open Mira patch grammar.
 *
 * @return list<array<string, mixed>>|WP_Error
 */
function openmira_parse_wp_patch(string $patch): array|WP_Error
{
    $normalized = str_replace(search: ["\r\n", "\r"], replace: "\n", subject: trim($patch));
    $match = [];
    if (!preg_match('/^\*\*\* Begin Patch\s*\n(?<body>.*)\n\*\*\* End Patch\s*$/s', $normalized, $match)) {
        return new WP_Error(
            'invalid_patch_wrapper',
            'Patch must be wrapped in "*** Begin Patch" and "*** End Patch" lines.',
        );
    }

    $body = $match['body'];
    $lines = explode("\n", $body);
    $hunks = [];
    $current_hunk = null;
    $current_body = [];

    foreach ($lines as $line) {
        $header = openmira_parse_wp_patch_header($line);
        if ($header !== null) {
            if ($current_hunk !== null) {
                $current_hunk['body'] = implode("\n", $current_body);
                $hunks[] = $current_hunk;
            }
            $current_hunk = $header;
            $current_body = [];
            continue;
        }

        if ($current_hunk !== null) {
            $current_body[] = $line;
        }
    }

    if ($current_hunk !== null) {
        $current_hunk['body'] = implode("\n", $current_body);
        $hunks[] = $current_hunk;
    }

    if ($hunks === []) {
        return new WP_Error('unsupported_patch_operation', 'Supported v1 hunk: *** Update theme.json (path: a.b.c):');
    }

    $operations = [];
    foreach ($hunks as $hunk) {
        if (!array_key_exists('type', $hunk) || !array_key_exists('options', $hunk)) {
            return new WP_Error('invalid_patch_hunk', 'Parsed patch hunk is missing required metadata.');
        }
        $operation = openmira_build_wp_patch_operation($hunk);
        if (is_wp_error($operation)) {
            return $operation;
        }
        $operations[] = $operation;
    }

    return $operations;
}

/**
 * Parse a patch hunk header into a dispatchable hunk.
 *
 * @return array<string, string>|null
 */
function openmira_parse_wp_patch_header(string $line): ?array
{
    $match = [];
    if (preg_match('/^\*\*\* Update theme\.json(?:\s*\((?<options>[^)]*)\))?:\s*$/', $line, $match)) {
        return [
            'type' => 'theme-json',
            'options' => $match['options'] ?? '',
            'body' => '',
        ];
    }

    if (preg_match(
        '/^\*\*\* (?<operation>Update|Insert|Delete) Block(?:\s*\((?<options>[^)]*)\))?:\s*$/',
        $line,
        $match,
    )) {
        return [
            'type' => 'block',
            'options' => trim($match['options'] ?? ''),
            'body' => '',
            'operation' => strtolower($match['operation']),
        ];
    }

    return null;
}

/**
 * Dispatch one parsed hunk to its operation builder.
 *
 * @param array<string, string> $hunk
 * @return array<string, mixed>|WP_Error
 */
function openmira_build_wp_patch_operation(array $hunk): array|WP_Error
{
    if ($hunk['type'] === 'theme-json') {
        return openmira_build_theme_json_operation($hunk['options'], $hunk['body']);
    }
    if ($hunk['type'] === 'block') {
        return openmira_build_block_patch_operation($hunk['operation'] ?? '', $hunk['options'], $hunk['body']);
    }

    return new WP_Error('unsupported_patch_operation', sprintf('Unsupported patch hunk type: %s', $hunk['type']));
}

/**
 * Return whether parsed operations contain block patch hunks.
 *
 * @param list<array<string, mixed>> $operations
 */
function openmira_wp_patch_has_block_operations(array $operations): bool
{
    foreach ($operations as $operation) {
        if (($operation['type'] ?? '') === 'block') {
            return true;
        }
    }

    return false;
}

/**
 * Apply parsed block hunks through patch-blocks.
 *
 * @param list<array<string, mixed>> $operations
 * @param array<string, mixed>       $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_apply_block_patch_operations(array $operations, array $input, bool $dry_run): array|WP_Error
{
    $block_operations = [];
    foreach ($operations as $operation) {
        if (($operation['type'] ?? '') !== 'block') {
            return new WP_Error(
                'mixed_patch_operations',
                'Block hunks cannot be mixed with theme.json hunks in one apply-patch call.',
            );
        }
        $patch_operation = is_array($operation['operation'] ?? null) ? $operation['operation'] : null;
        if ($patch_operation === null) {
            return new WP_Error(
                'invalid_block_patch_operation',
                'Parsed block hunk is missing its patch-blocks operation.',
            );
        }
        $block_operations[] = $patch_operation;
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id < 1) {
        return new WP_Error('missing_post_id', 'post_id is required for block patch hunks.');
    }

    if ($dry_run) {
        return [
            'dry_run' => true,
            'post_id' => $post_id,
            'operations' => $block_operations,
            'instructions' => 'Dry run parsed block operations only. Run again with dry_run=false to apply through openmira/patch-blocks.',
        ];
    }

    $result = openmira_patch_blocks([
        'post_id' => $post_id,
        'expected_etag' => (string) ($input['expected_etag'] ?? ''),
        'operations' => $block_operations,
        'create_backup' => true,
        'backup_note' => 'Automatic backup before apply-patch block hunks',
    ]);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['dry_run'] = false;
    $result['operations'] = $block_operations;

    // @mago-expect analysis:less-specific-return-statement
    return $result;
}

/**
 * Build one block operation from parsed hunk pieces.
 *
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_build_block_patch_operation(string $operation, string $options, string $body): array|WP_Error
{
    $parsed_options = openmira_parse_block_patch_options($options);
    if (is_wp_error($parsed_options)) {
        return $parsed_options;
    }

    if ($operation === 'update') {
        // @mago-expect analysis:mixed-assignment
        $value = openmira_decode_patch_json_value(trim($body));
        if (is_wp_error($value)) {
            return $value;
        }
        if (!is_array($value) || array_is_list($value)) {
            return new WP_Error('invalid_block_update_body', 'Update Block requires a JSON object body.');
        }
        if (!is_string($parsed_options['ref'] ?? null) || $parsed_options['ref'] === '') {
            return new WP_Error('missing_block_ref', 'Update Block requires a ref option.');
        }
        $patch_operation = [
            'operation' => 'update',
            'ref' => $parsed_options['ref'],
        ];
        $uses_wrapped_shape =
            array_key_exists('attrs', $value)
            || array_key_exists('attrs_mode', $value)
            || array_key_exists('inner_html', $value);
        if (array_key_exists('attrs', $value)) {
            if (!is_array($value['attrs'])) {
                return new WP_Error('invalid_block_attrs', 'Update Block attrs must be a JSON object.');
            }
            $patch_operation['attrs'] = $value['attrs'];
        } elseif (!$uses_wrapped_shape) {
            $patch_operation['attrs'] = $value;
        }
        if (is_string($value['attrs_mode'] ?? null)) {
            $patch_operation['attrs_mode'] = $value['attrs_mode'];
        }
        if (is_string($value['inner_html'] ?? null)) {
            $patch_operation['inner_html'] = $value['inner_html'];
        }

        return ['type' => 'block', 'operation' => $patch_operation];
    }

    if ($operation === 'insert') {
        $block_markup = trim($body);
        if ($block_markup === '') {
            return new WP_Error(
                'missing_block_markup',
                'Insert Block requires serialized block markup in the hunk body.',
            );
        }
        $patch_operation = [
            'operation' => 'insert',
            'block_markup' => $block_markup,
        ];
        foreach (['before', 'after', 'parent_ref'] as $key) {
            if (is_string($parsed_options[$key] ?? null) && $parsed_options[$key] !== '') {
                $patch_operation[$key] = $parsed_options[$key];
            }
        }
        if (is_int($parsed_options['index'] ?? null)) {
            $patch_operation['index'] = $parsed_options['index'];
        }

        return ['type' => 'block', 'operation' => $patch_operation];
    }

    if ($operation === 'delete') {
        if (!is_string($parsed_options['ref'] ?? null) || $parsed_options['ref'] === '') {
            return new WP_Error('missing_block_ref', 'Delete Block requires a ref option.');
        }

        return [
            'type' => 'block',
            'operation' => [
                'operation' => 'delete',
                'ref' => $parsed_options['ref'],
            ],
        ];
    }

    return new WP_Error('unsupported_block_patch_operation', sprintf(
        'Unsupported block patch operation: %s',
        $operation,
    ));
}

/**
 * Parse block hunk options like "ref: omr_..., after: omr_...".
 *
 * @return array<string, string|int>|WP_Error
 */
function openmira_parse_block_patch_options(string $options): array|WP_Error
{
    $parsed = [];
    foreach (array_filter(array_map('trim', explode(',', $options))) as $part) {
        if (!str_contains($part, ':')) {
            return new WP_Error('invalid_block_patch_option', sprintf('Invalid block hunk option: %s', $part));
        }
        [$key, $value] = array_map('trim', explode(':', $part, 2));
        if (!in_array($key, ['ref', 'before', 'after', 'parent_ref', 'index'], strict: true)) {
            return new WP_Error('invalid_block_patch_option', sprintf('Unsupported block hunk option: %s', $key));
        }
        if ($key === 'index') {
            if (!ctype_digit($value)) {
                return new WP_Error('invalid_block_patch_index', 'Block hunk index must be a zero-based integer.');
            }
            $parsed[$key] = (int) $value;
            continue;
        }
        $parsed[$key] = $value;
    }

    return $parsed;
}

/**
 * Build one theme.json operation from parsed hunk pieces.
 *
 * @return array<string, mixed>|WP_Error
 */
function openmira_build_theme_json_operation(string $options, string $json_body): array|WP_Error
{
    $parsed_options = openmira_parse_theme_json_patch_options($options);
    if (is_wp_error($parsed_options)) {
        return $parsed_options;
    }

    // @mago-expect analysis:mixed-assignment
    $value = openmira_decode_patch_json_value(trim($json_body));
    if (is_wp_error($value)) {
        return $value;
    }

    if ($parsed_options['paths']) {
        if (!is_array($value) || array_is_list($value)) {
            return new WP_Error(
                'invalid_theme_json_paths_value',
                'Bulk theme.json patches require a JSON object keyed by theme.json paths.',
            );
        }

        return [
            'type' => 'theme-json-bulk',
            'mode' => $parsed_options['mode'],
            'values' => $value,
        ];
    }

    return [
        'type' => 'theme-json',
        'path' => $parsed_options['path'],
        'mode' => $parsed_options['mode'],
        'value' => $value,
    ];
}

/**
 * Parse hunk options like "path: styles.color, mode: merge".
 *
 * @return array{path: string, mode: string, paths: bool}|WP_Error
 */
function openmira_parse_theme_json_patch_options(string $options): array|WP_Error
{
    $path = '';
    $mode = 'replace';
    $paths = false;
    foreach (array_filter(array_map('trim', explode(',', $options))) as $part) {
        if (!str_contains($part, ':')) {
            if (strtolower($part) === 'paths') {
                $paths = true;
                continue;
            }
            $path = $path === '' ? $part : $path;
            continue;
        }
        [$key, $value] = array_map('trim', explode(separator: ':', string: $part, limit: 2));
        if ($key === 'path') {
            $path = $value;
            continue;
        }
        if ($key === 'mode') {
            $mode = strtolower($value);
        }
    }

    if ($path === '' && !$paths) {
        return new WP_Error(
            'missing_theme_json_path',
            'Update theme.json hunks require a path option or the paths bulk option.',
        );
    }

    if ($path !== '' && $paths) {
        return new WP_Error('invalid_theme_json_options', 'Use either path: a.b.c or paths, not both.');
    }

    if ($path !== '' && !openmira_is_valid_theme_json_path($path)) {
        return new WP_Error('invalid_theme_json_path', sprintf('Invalid theme.json path: %s', $path));
    }

    if (!in_array($mode, ['replace', 'merge'], strict: true)) {
        return new WP_Error('invalid_theme_json_mode', 'theme.json patch mode must be replace or merge.');
    }

    return ['path' => $path, 'mode' => $mode, 'paths' => $paths];
}

/**
 * Return whether a theme.json path is safe for semantic patching.
 */
function openmira_is_valid_theme_json_path(string $path): bool
{
    return (
        preg_match(
            '/^[A-Za-z0-9_$\/-]+(?:\[[A-Za-z0-9_$\/=-]+\])?(?:\.[A-Za-z0-9_$\/-]+(?:\[[A-Za-z0-9_$\/=-]+\])?)*$/',
            $path,
        ) === 1
    );
}

/**
 * Decode a JSON value from a patch hunk body.
 *
 * @return mixed|WP_Error
 */
function openmira_decode_patch_json_value(string $json_body): mixed
{
    $body = trim($json_body);
    if (str_starts_with($body, '```')) {
        $body = preg_replace(pattern: '/^```(?:json)?\s*\n|\n```\s*$/', replacement: '', subject: $body);
        $body = is_string($body) ? trim($body) : '';
    }
    if ($body === '') {
        return new WP_Error('empty_theme_json_value', 'theme.json patch hunk body must contain a JSON value.');
    }

    // @mago-expect analysis:mixed-assignment
    $value = json_decode(json: $body, associative: true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('invalid_theme_json_value', sprintf(
            'theme.json patch body is not valid JSON: %s',
            json_last_error_msg(),
        ));
    }

    return $value;
}

/**
 * Decode existing theme.json into an array.
 *
 * @return array<string, mixed>|WP_Error
 */
function openmira_decode_theme_json(?string $content): array|WP_Error
{
    if ($content === null || trim($content) === '') {
        return ['version' => openmira_theme_json_latest_schema()];
    }

    // @mago-expect analysis:mixed-assignment
    $decoded = json_decode(json: $content, associative: true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return new WP_Error('invalid_theme_json', sprintf(
            'Existing theme.json is not valid JSON: %s',
            json_last_error_msg(),
        ));
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($decoded as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $normalized[$key] = $value;
    }

    if (!array_key_exists('version', $normalized)) {
        $normalized['version'] = openmira_theme_json_latest_schema();
    }

    return $normalized;
}

/**
 * Return current WordPress theme.json schema version.
 */
function openmira_theme_json_latest_schema(): int
{
    return class_exists('WP_Theme_JSON') && defined('WP_Theme_JSON::LATEST_SCHEMA')
        ? (int) constant('WP_Theme_JSON::LATEST_SCHEMA')
        : 3;
}

/**
 * Apply one semantic theme.json operation.
 *
 * @param array<string, mixed> $theme_json
 * @param array<string, mixed> $operation
 * @return array<string, mixed>|WP_Error
 */
function openmira_apply_theme_json_operation(array &$theme_json, array $operation): array|WP_Error
{
    $type = (string) ($operation['type'] ?? 'theme-json');
    if ($type === 'theme-json-bulk') {
        return openmira_apply_theme_json_bulk_operation($theme_json, $operation);
    }

    $path = (string) ($operation['path'] ?? '');
    $mode = (string) ($operation['mode'] ?? 'replace');
    // @mago-expect analysis:mixed-assignment
    $value = $operation['value'] ?? null;

    $set = openmira_theme_json_set_path($theme_json, $path, $value, $mode);
    if (is_wp_error($set)) {
        return $set;
    }

    return ['type' => 'theme-json', 'path' => $path, 'mode' => $mode];
}

/**
 * Apply a bulk theme.json operation keyed by semantic paths.
 *
 * @param array<string, mixed> $theme_json
 * @param array<string, mixed> $operation
 * @return array<string, mixed>|WP_Error
 */
function openmira_apply_theme_json_bulk_operation(array &$theme_json, array $operation): array|WP_Error
{
    $default_mode = (string) ($operation['mode'] ?? 'replace');
    if (!in_array($default_mode, ['replace', 'merge'], strict: true)) {
        return new WP_Error('invalid_theme_json_mode', 'theme.json patch mode must be replace or merge.');
    }

    // @mago-expect analysis:mixed-assignment
    $values = $operation['values'] ?? null;
    if (!is_array($values) || array_is_list($values)) {
        return new WP_Error(
            'invalid_theme_json_paths_value',
            'Bulk theme.json patches require a JSON object keyed by theme.json paths.',
        );
    }

    $applied = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($values as $path => $raw_value) {
        if (!is_string($path) || !openmira_is_valid_theme_json_path($path)) {
            return new WP_Error('invalid_theme_json_path', sprintf('Invalid theme.json path: %s', (string) $path));
        }

        $mode = $default_mode;
        // @mago-expect analysis:mixed-assignment
        $value = $raw_value;
        if (is_array($raw_value) && array_key_exists('value', $raw_value)) {
            $mode = strtolower((string) ($raw_value['mode'] ?? $default_mode));
            // @mago-expect analysis:mixed-assignment
            $value = $raw_value['value'];
        }

        if (!in_array($mode, ['replace', 'merge'], strict: true)) {
            return new WP_Error('invalid_theme_json_mode', 'theme.json patch mode must be replace or merge.');
        }

        $set = openmira_theme_json_set_path($theme_json, $path, $value, $mode);
        if (is_wp_error($set)) {
            return $set;
        }

        $applied[] = ['path' => $path, 'mode' => $mode];
    }

    return [
        'type' => 'theme-json-bulk',
        'paths' => array_column($applied, 'path'),
        'operations' => $applied,
    ];
}

/**
 * Set or merge a nested theme.json value.
 *
 * @param array<string, mixed> $theme_json
 * @param mixed               $value
 * @return true|WP_Error
 */
function openmira_theme_json_set_path(array &$theme_json, string $path, mixed $value, string $mode): bool|WP_Error
{
    $segments = explode('.', $path);
    $cursor = &$theme_json;
    foreach ($segments as $segment_index => $segment) {
        $parsed = openmira_parse_theme_json_path_segment($segment);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $key = $parsed['key'];
        $selector = $parsed['selector'];
        $is_last = $segment_index === array_key_last($segments);
        if ($selector === '') {
            if ($is_last) {
                $cursor[$key] = $mode === 'merge'
                    ? openmira_merge_theme_json_values($cursor[$key] ?? [], $value)
                    : $value;
                return true;
            }
            if (!array_key_exists($key, $cursor) || !is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
            continue;
        }

        if (!array_key_exists($key, $cursor) || !is_array($cursor[$key])) {
            $cursor[$key] = [];
        }
        $entry_index = openmira_theme_json_find_list_entry($cursor[$key], $selector);
        if ($entry_index === -1) {
            $cursor[$key][] = ['slug' => $selector];
            $entry_index = count($cursor[$key]) - 1;
        }

        if ($is_last) {
            $cursor[$key][$entry_index] = $mode === 'merge'
                ? openmira_merge_theme_json_values($cursor[$key][$entry_index] ?? [], $value)
                : $value;
            return true;
        }
        if (!is_array($cursor[$key][$entry_index])) {
            $cursor[$key][$entry_index] = [];
        }
        $cursor = &$cursor[$key][$entry_index];
    }

    return true;
}

/**
 * Parse one path segment, with optional list selector: palette[primary].
 *
 * @return array{key: string, selector: string}|WP_Error
 */
function openmira_parse_theme_json_path_segment(string $segment): array|WP_Error
{
    $match = [];
    if (!preg_match('/^(?<key>[A-Za-z0-9_$\/-]+)(?:\[(?<selector>[A-Za-z0-9_$\/=-]+)\])?$/', $segment, $match)) {
        return new WP_Error('invalid_theme_json_path_segment', sprintf(
            'Invalid theme.json path segment: %s',
            $segment,
        ));
    }

    $selector = $match['selector'] ?? '';
    if (str_starts_with($selector, 'slug=')) {
        $selector = substr($selector, strlen('slug='));
    }

    return ['key' => $match['key'], 'selector' => $selector];
}

/**
 * Find an array entry by numeric index or object slug/name.
 *
 * @param mixed $list
 */
function openmira_theme_json_find_list_entry(mixed $list, string $selector): int
{
    if (!is_array($list)) {
        return -1;
    }
    if (ctype_digit($selector)) {
        $index = (int) $selector;
        return array_key_exists($index, $list) ? $index : -1;
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($list as $index => $entry) {
        if (!is_int($index) || !is_array($entry)) {
            continue;
        }
        $slug = (string) ($entry['slug'] ?? '');
        $name = sanitize_title((string) ($entry['name'] ?? ''));
        if ($slug === $selector || $name === sanitize_title($selector)) {
            return $index;
        }
    }

    return -1;
}

/**
 * Merge object-like JSON arrays recursively; replace lists and scalars.
 *
 * @param mixed $existing
 * @param mixed $incoming
 * @return mixed
 */
function openmira_merge_theme_json_values(mixed $existing, mixed $incoming): mixed
{
    if (!is_array($existing) || !is_array($incoming)) {
        return $incoming;
    }
    if (array_is_list($existing) || array_is_list($incoming)) {
        return $incoming;
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($incoming as $key => $value) {
        $existing[$key] = array_key_exists($key, $existing)
            ? openmira_merge_theme_json_values($existing[$key], $value)
            : $value;
    }

    return $existing;
}

/**
 * Validate theme.json through WordPress where available.
 *
 * @param array<string, mixed> $theme_json
 * @return true|WP_Error
 */
function openmira_validate_theme_json_data(array $theme_json): bool|WP_Error
{
    $version = (int) ($theme_json['version'] ?? 0);
    if ($version < 1 || $version > openmira_theme_json_latest_schema()) {
        return new WP_Error('invalid_theme_json_version', sprintf('Unsupported theme.json version: %d', $version));
    }

    if (!class_exists('WP_Theme_JSON')) {
        return true;
    }

    try {
        new WP_Theme_JSON($theme_json, 'theme');
    } catch (Throwable $throwable) {
        return new WP_Error('invalid_theme_json_schema', $throwable->getMessage());
    }

    return true;
}

/**
 * Encode theme.json consistently.
 *
 * @param array<string, mixed> $theme_json
 * @return string|WP_Error
 */
function openmira_encode_theme_json(array $theme_json): string|WP_Error
{
    $encoded = wp_json_encode($theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return new WP_Error('theme_json_encode_failed', 'Could not encode theme.json.');
    }

    return $encoded . "\n";
}
