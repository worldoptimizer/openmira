<?php

declare(strict_types=1);

/**
 * Ability: Promote a sandbox PHP file into a real WordPress plugin.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/graduate-sandbox-plugin', [
    'label' => __('Graduate Sandbox Plugin', domain: 'open-mira'),
    'description' => __(
        'Promotes a tested PHP file from wp-content/openmira-sandbox/ into wp-content/plugins/<slug>/ with linting, backups, audit, and duplicate-load protection.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'source_path' => [
                'type' => 'string',
                'description' => 'Sandbox PHP file to promote. Accepts active .php files or .php.disabled files.',
                'minLength' => 1,
            ],
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Destination plugin directory slug. Defaults to the source filename slug.',
                'default' => '',
            ],
            'main_file' => [
                'type' => 'string',
                'description' => 'Destination main plugin filename. Defaults to <plugin_slug>.php.',
                'default' => '',
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Overwrite an existing destination file after stale-write protection.',
                'default' => false,
            ],
            'disable_source' => [
                'type' => 'boolean',
                'description' => 'Rename the sandbox source to .disabled after copying. Keep true before activation to avoid duplicate function/class fatals.',
                'default' => true,
            ],
            'activate' => [
                'type' => 'boolean',
                'description' => 'Activate the promoted plugin when safe. If the active sandbox file was loaded in this request, activation is deferred to the next request.',
                'default' => false,
            ],
            'expected_source_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash expected for the sandbox source file.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
            'expected_target_hash' => [
                'type' => 'string',
                'description' => 'Optional SHA-256 hash expected for an existing target file.',
                'pattern' => '^[a-f0-9]{64}$',
            ],
        ],
        'required' => ['source_path'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_graduate_sandbox_plugin',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use after a sandbox plugin is linted and verified.',
                'Default flow: source_path from wp-content/openmira-sandbox/*.php, disable_source=true, activate=false.',
                'If activation is deferred, call this ability again with the .disabled source_path and activate=true on the next request.',
                'Do not copy sandbox PHP into wp-content/plugins with execute-php; use this ability for audit, backup, lint, and duplicate-load safety.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Promote a sandbox PHP file to a real plugin directory.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
// @mago-expect lint:kan-defect
function openmira_graduate_sandbox_plugin(array $input): array|WP_Error
{
    $mode_error = openmira_require_act_mode('openmira/graduate-sandbox-plugin');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $started_at = microtime(as_float: true);
    $source = openmira_graduate_resolve_source((string) ($input['source_path'] ?? ''));
    if (is_wp_error($source)) {
        return $source;
    }

    $source_hash = hash_file(algo: 'sha256', filename: $source);
    if (!is_string($source_hash)) {
        return new WP_Error('source_hash_failed', 'Could not hash sandbox source file.');
    }

    $expected_source_hash = (string) ($input['expected_source_hash'] ?? '');
    if ($expected_source_hash !== '' && $expected_source_hash !== $source_hash) {
        return new WP_Error('source_hash_mismatch', 'Sandbox source does not match expected_source_hash.', [
            'source_path' => openmira_display_path($source),
            'expected_source_hash' => $expected_source_hash,
            'current_source_hash' => $source_hash,
        ]);
    }

    $source_lint = openmira_php_lint_file($source);
    if ($source_lint['ok'] !== true) {
        return new WP_Error('source_php_syntax_failed', 'Sandbox source PHP syntax check failed.', [
            'source_path' => openmira_display_path($source),
            'syntax' => $source_lint,
        ]);
    }

    $plugin_slug = openmira_graduate_plugin_slug((string) ($input['plugin_slug'] ?? ''), $source);
    if (is_wp_error($plugin_slug)) {
        return $plugin_slug;
    }
    $main_file = openmira_graduate_main_file((string) ($input['main_file'] ?? ''), $plugin_slug);
    if (is_wp_error($main_file)) {
        return $main_file;
    }

    $target_dir = trailingslashit(WP_PLUGIN_DIR) . $plugin_slug;
    $target = $target_dir . '/' . $main_file;
    $target_check = openmira_graduate_validate_target($target);
    if (is_wp_error($target_check)) {
        return $target_check;
    }

    $overwrite = ($input['overwrite'] ?? false) === true;
    $target_exists = is_file($target);
    $target_old_content = $target_exists ? file_get_contents($target) : null;
    if ($target_old_content === false) {
        return new WP_Error('target_read_failed', sprintf('Could not read target plugin file: %s', $target));
    }

    $target_hash = $target_exists ? hash_file(algo: 'sha256', filename: $target) : '';
    if ($target_exists && !is_string($target_hash)) {
        return new WP_Error('target_hash_failed', 'Could not hash target plugin file.');
    }

    $same_target = $target_exists && $target_hash === $source_hash;
    if ($target_exists && !$same_target && !$overwrite) {
        return new WP_Error(
            'target_exists',
            'Destination plugin file already exists. Set overwrite=true after reading it, or choose another plugin_slug/main_file.',
            [
                'target_path' => openmira_display_path($target),
                'current_target_hash' => $target_hash,
            ],
        );
    }

    if ($target_exists && !$same_target) {
        $fresh_read = openmira_require_fresh_file_read(
            resolved: $target,
            ability: 'openmira/graduate-sandbox-plugin',
            expected_hash: (string) ($input['expected_target_hash'] ?? ''),
        );
        if (is_wp_error($fresh_read)) {
            return $fresh_read;
        }
    }

    $source_content = file_get_contents($source);
    if (!is_string($source_content)) {
        return new WP_Error('source_read_failed', sprintf('Could not read sandbox source: %s', $source));
    }

    $directory_created = false;
    if (!is_dir($target_dir)) {
        clearstatcache(clear_realpath_cache: true, filename: $target_dir);
        $directory_created = wp_mkdir_p($target_dir);
        clearstatcache(clear_realpath_cache: true, filename: $target_dir);
        if (!$directory_created || !is_dir($target_dir)) {
            return new WP_Error('target_directory_failed', sprintf('Failed to create directory: %s', $target_dir), [
                'target_dir' => openmira_display_path($target_dir),
                'diagnostics' => openmira_graduate_path_diagnostics($target_dir),
                'hint' => 'The path may be blocked by a stale virtual-filesystem entry. Retry after clearing it, or choose another plugin_slug.',
            ]);
        }
    }

    $backup = $target_exists && !$same_target
        ? openmira_create_file_backup($target, operation: 'graduate-sandbox-plugin')
        : null;
    $copied = false;
    if (!$same_target) {
        $bytes_written = file_put_contents($target, $source_content, LOCK_EX);
        if ($bytes_written === false) {
            return openmira_graduate_error(
                code: 'target_write_failed',
                message: 'Could not write promoted plugin file.',
                started_at: $started_at,
                target: $target,
                backup: $backup,
            );
        }
        chmod(filename: $target, permissions: 0644);
        $copied = true;
    }

    $target_lint = openmira_php_lint_file($target);
    if ($target_lint['ok'] !== true) {
        if (!$same_target) {
            openmira_rollback_failed_php_write($target, $target_old_content);
        }
        return openmira_graduate_error(
            code: 'target_php_syntax_failed',
            message: 'Promoted plugin PHP syntax check failed.',
            started_at: $started_at,
            target: $target,
            backup: $backup,
            data: ['syntax' => $target_lint],
        );
    }

    $source_was_disabled = openmira_is_disabled_file($source);
    $disabled_source = null;
    if (($input['disable_source'] ?? true) !== false && !$source_was_disabled) {
        $disabled_source = openmira_graduate_disable_source($source);
        if (is_wp_error($disabled_source)) {
            return $disabled_source;
        }
    }

    $plugin_file = $plugin_slug . '/' . $main_file;
    $activation = openmira_graduate_activation_result(
        requested: ($input['activate'] ?? false) === true,
        plugin_file: $plugin_file,
        source_was_disabled: $source_was_disabled,
        source_disabled_now: is_string($disabled_source),
    );

    $diff = openmira_build_unified_diff($target_old_content, $source_content, $target);
    $audit = openmira_record_audit_event([
        'ability' => 'openmira/graduate-sandbox-plugin',
        'operation' => 'graduate',
        'target_path' => openmira_display_path($target),
        'status' => 'success',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'diff_summary' => openmira_diff_summary($diff),
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    $result = [
        'source_path' => openmira_display_path($source),
        'source_hash' => $source_hash,
        'source_disabled' => is_string($disabled_source),
        'disabled_source_path' => is_string($disabled_source) ? openmira_display_path($disabled_source) : '',
        'target_path' => openmira_display_path($target),
        'plugin_file' => $plugin_file,
        'plugin_slug' => $plugin_slug,
        'main_file' => $main_file,
        'copied' => $copied,
        'target_hash' => openmira_file_hash_content($source_content),
        'directories_created' => $directory_created ? [openmira_display_path($target_dir)] : [],
        'diff' => $diff,
        'lint' => ['source' => $source_lint, 'target' => $target_lint],
        'activation' => $activation,
        'next_steps' => openmira_graduate_next_steps($activation, $disabled_source, $plugin_file),
        'audit' => $audit,
    ];
    if (is_array($backup)) {
        $result['backup'] = $backup;
    }

    return $result;
}

/**
 * Resolve and validate a source file inside the Open Mira sandbox.
 */
function openmira_graduate_resolve_source(string $path): string|WP_Error
{
    $resolved = openmira_resolve_path($path, must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }
    $sandbox_check = openmira_validate_sandbox_path($resolved);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }
    if (!is_file($resolved) || !is_readable($resolved)) {
        return new WP_Error('source_not_readable', 'Sandbox source is not a readable file.');
    }
    if (!preg_match('/\\.php(\\.disabled)?$/', $resolved)) {
        return new WP_Error('source_not_php', 'Sandbox source must be a .php file or .php.disabled file.');
    }

    return $resolved;
}

/**
 * Return a safe plugin slug.
 */
function openmira_graduate_plugin_slug(string $plugin_slug, string $source): string|WP_Error
{
    if ($plugin_slug === '') {
        $basename = basename($source);
        $basename = preg_replace(pattern: '/\\.php(\\.disabled)?$/', replacement: '', subject: $basename) ?? $basename;
        $plugin_slug = $basename;
    }

    $plugin_slug = sanitize_title($plugin_slug);
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $plugin_slug)) {
        return new WP_Error('invalid_plugin_slug', 'plugin_slug must contain lowercase letters, numbers, and dashes.');
    }

    return $plugin_slug;
}

/**
 * Return a safe main plugin filename.
 */
function openmira_graduate_main_file(string $main_file, string $plugin_slug): string|WP_Error
{
    $main_file = $main_file !== '' ? sanitize_file_name($main_file) : $plugin_slug . '.php';
    if (!preg_match('/^[a-z0-9][a-z0-9-_]*\\.php$/', $main_file)) {
        return new WP_Error('invalid_main_file', 'main_file must be a safe lowercase PHP filename.');
    }

    return $main_file;
}

/**
 * Ensure target path remains inside wp-content/plugins.
 */
function openmira_graduate_validate_target(string $target): bool|WP_Error
{
    $plugin_dir = realpath(WP_PLUGIN_DIR);
    if (!is_string($plugin_dir)) {
        return new WP_Error('plugin_dir_missing', 'WordPress plugin directory does not exist.');
    }

    $target_parent = realpath(dirname($target));
    if (!is_string($target_parent)) {
        $target_parent = dirname($target);
    }
    $normalized_parent = wp_normalize_path($target_parent);
    $normalized_plugin_dir = wp_normalize_path($plugin_dir);

    if (!str_starts_with($normalized_parent, $normalized_plugin_dir)) {
        return new WP_Error('target_outside_plugins', 'Promoted plugin target must stay inside wp-content/plugins.');
    }

    return true;
}

/**
 * Disable the sandbox source after copying it.
 */
function openmira_graduate_disable_source(string $source): string|WP_Error
{
    $disabled = $source . '.disabled';
    clearstatcache(clear_realpath_cache: true, filename: $disabled);
    if (is_file($disabled)) {
        return new WP_Error('disabled_source_exists', sprintf('A disabled source already exists: %s', $disabled), [
            'disabled_path' => openmira_display_path($disabled),
            'diagnostics' => openmira_graduate_path_diagnostics($disabled),
            'hint' => 'Pass the .disabled source_path on the next activation request, or remove the existing disabled file if it is stale.',
        ]);
    }
    if (!rename($source, $disabled)) {
        clearstatcache(clear_realpath_cache: true, filename: $disabled);
        return new WP_Error('disable_source_failed', sprintf('Could not disable sandbox source: %s', $source), [
            'source_path' => openmira_display_path($source),
            'disabled_path' => openmira_display_path($disabled),
            'diagnostics' => openmira_graduate_path_diagnostics($disabled),
            'hint' => 'A stale virtual-filesystem entry may be blocking the .disabled path. Materialize or remove it before retrying.',
        ]);
    }

    return $disabled;
}

/**
 * Return filesystem diagnostics for a path that may be blocked by a ghost entry.
 *
 * @return array<string, mixed>
 */
function openmira_graduate_path_diagnostics(string $path): array
{
    clearstatcache(clear_realpath_cache: true, filename: $path);
    $stat = openmira_graduate_safe_stat($path);
    $realpath = realpath($path);

    return [
        'path' => openmira_display_path($path),
        'file_exists' => file_exists($path),
        'is_dir' => is_dir($path),
        'is_file' => is_file($path),
        'is_link' => is_link($path),
        'is_readable' => is_readable($path),
        'is_writable' => is_writable($path),
        'realpath' => is_string($realpath) ? $realpath : '',
        'stat' => is_array($stat)
            ? [
                'mode' => $stat['mode'],
                'size' => $stat['size'],
                'mtime' => $stat['mtime'],
            ]
            : null,
    ];
}

/**
 * Return stat data without leaking filesystem warnings into ability responses.
 *
 * @return array<string, mixed>|false
 */
function openmira_graduate_safe_stat(string $path): array|false
{
    set_error_handler(static fn(): bool => true);
    $stat = stat($path);
    restore_error_handler();

    return $stat;
}

/**
 * Return activation state, activating only when duplicate-load risk is absent.
 *
 * @return array<string, mixed>
 */
function openmira_graduate_activation_result(
    bool $requested,
    string $plugin_file,
    bool $source_was_disabled,
    bool $source_disabled_now,
): array {
    if (!$requested) {
        return [
            'requested' => false,
            'status' => 'not_requested',
            'plugin_file' => $plugin_file,
            'message' => 'Promotion completed without activation.',
        ];
    }

    if ($source_disabled_now && !$source_was_disabled) {
        return [
            'requested' => true,
            'status' => 'deferred',
            'plugin_file' => $plugin_file,
            'message' => 'Sandbox source was loaded earlier in this request and has now been disabled. Activate on the next request to avoid duplicate function/class fatals.',
        ];
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $activation = activate_plugin($plugin_file);
    if (is_wp_error($activation)) {
        return [
            'requested' => true,
            'status' => 'error',
            'plugin_file' => $plugin_file,
            'message' => $activation->get_error_message(),
        ];
    }

    return [
        'requested' => true,
        'status' => is_plugin_active($plugin_file) ? 'active' : 'unknown',
        'plugin_file' => $plugin_file,
        'message' => is_plugin_active($plugin_file)
            ? 'Plugin activated.'
            : 'Activation completed, but plugin is not reported active.',
    ];
}

/**
 * Return next-step hints for agents.
 *
 * @param string|WP_Error|null $disabled_source
 * @return list<string>
 */
function openmira_graduate_next_steps(array $activation, mixed $disabled_source, string $plugin_file): array
{
    if (($activation['status'] ?? '') === 'deferred' && is_string($disabled_source)) {
        return [
            sprintf(
                'Call openmira/graduate-sandbox-plugin again with source_path "%s" and activate=true to activate %s on a fresh request.',
                openmira_display_path($disabled_source),
                $plugin_file,
            ),
        ];
    }

    if (($activation['status'] ?? '') === 'active') {
        return ['Verify the promoted plugin through probe-url or screenshot-url.'];
    }

    return ['Use run-wpcli plugin activate or call this ability with activate=true when ready.'];
}

/**
 * Record and return a promotion error.
 *
 * @param array<array-key, mixed>|null $backup
 * @param array<string, mixed>        $data
 */
// @mago-expect lint:excessive-parameter-list
function openmira_graduate_error(
    string $code,
    string $message,
    float $started_at,
    string $target,
    ?array $backup,
    array $data = [],
): WP_Error {
    openmira_record_audit_event([
        'ability' => 'openmira/graduate-sandbox-plugin',
        'operation' => 'graduate',
        'target_path' => openmira_display_path($target),
        'status' => 'error',
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'error' => $code,
        'backup_id' => is_array($backup) ? (string) ($backup['id'] ?? '') : '',
    ]);

    return new WP_Error($code, $message, array_merge(['target_path' => openmira_display_path($target)], $data));
}
