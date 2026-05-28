<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Run allowlisted WP-CLI commands.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_WPCLI_JOBS_DIR = WP_CONTENT_DIR . '/openmira-wpcli-jobs';

wp_register_ability('openmira/run-wpcli', [
    'label' => __('Run WP-CLI', domain: 'open-mira'),
    'description' => __(
        'Runs a narrow allowlist of WP-CLI commands against this WordPress install.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'args' => [
                'type' => 'array',
                'description' => 'WP-CLI arguments without the leading wp, e.g. ["theme","list"].',
                'items' => ['type' => 'string'],
                'minItems' => 2,
            ],
            'command' => [
                'type' => 'string',
                'description' => 'Alias for args as a command string without the leading wp, e.g. "theme list".',
                'minLength' => 1,
            ],
            'timeout_seconds' => ['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 120],
            'mode' => [
                'type' => 'string',
                'description' => 'Run synchronously or create an async job for longer commands.',
                'enum' => ['sync', 'async'],
                'default' => 'sync',
            ],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_run_wpcli',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for validation, WordPress-native inspections, and narrow content setup. Commands are allowlisted.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('openmira/get-wpcli-job', [
    'label' => __('Get WP-CLI Job', domain: 'open-mira'),
    'description' => __('Polls an async WP-CLI job and returns incremental log output.', domain: 'open-mira'),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'description' => 'Job ID returned by run-wpcli mode=async.'],
            'log_offset' => [
                'type' => 'integer',
                'description' => 'Byte offset for incremental log reads.',
                'minimum' => 0,
                'default' => 0,
            ],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_get_wpcli_job',
    'permission_callback' => 'openmira_permission_callback',
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

/**
 * Run an allowlisted WP-CLI command.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_run_wpcli(array $input): array|WP_Error
{
    $args = openmira_normalize_wpcli_args($input['args'] ?? $input['command'] ?? []);
    if (count($args) < 2) {
        return new WP_Error(
            'invalid_wpcli_args',
            'Provide args as ["command","subcommand"] or command as "command subcommand".',
        );
    }

    $policy = openmira_wpcli_policy($args);
    if (is_wp_error($policy)) {
        return $policy;
    }
    if (($policy['requires_act'] ?? false) === true) {
        $mode_error = openmira_require_act_mode('openmira/run-wpcli');
        if (is_wp_error($mode_error)) {
            return $mode_error;
        }
    }

    $binary = openmira_find_wpcli_binary();
    if ($binary === '') {
        return new WP_Error('wpcli_not_found', 'WP-CLI binary was not found on this server.');
    }

    $root_error = openmira_refuse_wpcli_root_unless_allowed();
    if (is_wp_error($root_error)) {
        return $root_error;
    }

    $timeout = max(1, min(120, (int) ($input['timeout_seconds'] ?? 30)));
    $command = openmira_build_wpcli_command($binary, $args, $timeout);
    $mode = (string) ($input['mode'] ?? 'sync');
    if ($mode === 'async') {
        return openmira_start_wpcli_job($args, $policy, $command, $timeout);
    }

    $started_at = microtime(as_float: true);
    $output = [];
    $exit_code = 1;
    exec(command: $command . ' 2>&1', output: $output, result_code: $exit_code);

    return [
        'ok' => $exit_code === 0,
        'exit_code' => $exit_code,
        'command' => $command,
        'args' => $args,
        'policy' => $policy,
        'duration_ms' => (int) round((microtime(as_float: true) - $started_at) * 1000),
        'output' => implode("\n", $output),
    ];
}

/**
 * Poll an async WP-CLI job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_get_wpcli_job(array $input): array|WP_Error
{
    openmira_cleanup_old_wpcli_jobs();

    $job_id = sanitize_key((string) ($input['job_id'] ?? ''));
    if ($job_id === '') {
        return new WP_Error('missing_wpcli_job_id', 'job_id is required.');
    }

    $job_dir = openmira_wpcli_job_dir($job_id);
    if (!is_dir($job_dir)) {
        return new WP_Error('wpcli_job_not_found', 'WP-CLI job not found.', ['job_id' => $job_id]);
    }

    $meta = openmira_read_wpcli_job_json($job_dir . '/meta.json');
    if (is_wp_error($meta)) {
        return $meta;
    }
    $done = is_file($job_dir . '/done.json') ? openmira_read_wpcli_job_json($job_dir . '/done.json') : null;
    if (is_wp_error($done)) {
        return $done;
    }

    $status = 'running';
    $exit_code = null;
    if (is_array($done)) {
        $exit_code = (int) ($done['exit_code'] ?? 1);
        $status = $exit_code === 0 ? 'complete' : 'failed';
    } elseif (!openmira_wpcli_process_is_running((int) ($meta['pid'] ?? 0))) {
        $status = 'unknown';
    }

    $log_path = $job_dir . '/output.log';
    $log_size = is_file($log_path) ? (int) filesize($log_path) : 0;
    $offset = max(0, min((int) ($input['log_offset'] ?? 0), $log_size));
    $log = '';
    if ($log_size > $offset) {
        $chunk = file_get_contents(filename: $log_path, use_include_path: false, context: null, offset: $offset);
        $log = is_string($chunk) ? $chunk : '';
    }

    return [
        'job_id' => $job_id,
        'status' => $status,
        'exit_code' => $exit_code,
        'args' => is_array($meta['args'] ?? null) ? $meta['args'] : [],
        'command' => (string) ($meta['command'] ?? ''),
        'started_at' => (string) ($meta['started_at'] ?? ''),
        'finished_at' => is_array($done) ? (string) ($done['finished_at'] ?? '') : '',
        'pid' => (int) ($meta['pid'] ?? 0),
        'log' => $log,
        'log_offset' => $offset,
        'next_log_offset' => $log_size,
        'log_size' => $log_size,
    ];
}

/**
 * @return list<string>
 */
function openmira_normalize_wpcli_args(mixed $args): array
{
    if (is_string($args)) {
        $tokens = str_getcsv($args, separator: ' ', enclosure: '"', escape: '\\');
        $args = $tokens;
    }

    if (!is_array($args)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($args as $arg) {
        if (!is_scalar($arg)) {
            continue;
        }
        $value = trim((string) $arg);
        if ($value !== '') {
            $normalized[] = $value;
        }
    }

    return $normalized;
}

/**
 * @param list<string> $args
 * @return array<string, mixed>|WP_Error
 */
function openmira_wpcli_policy(array $args): array|WP_Error
{
    $command = $args[0] ?? '';
    $subcommand = $args[1] ?? '';
    $signature = $command . ' ' . $subcommand;
    $read_only = ['plugin list', 'theme list', 'option get', 'post list', 'post get'];
    $mutating = [
        'plugin activate',
        'plugin deactivate',
        'theme activate',
        'eval-file',
        'i18n make-pot',
        'post create',
        'post meta',
        'post term',
        'term create',
    ];

    if (in_array($signature, $read_only, strict: true)) {
        return ['allowlisted' => true, 'requires_act' => false, 'signature' => $signature];
    }
    if (in_array($signature, $mutating, strict: true) || $command === 'eval-file') {
        return ['allowlisted' => true, 'requires_act' => true, 'signature' => $signature];
    }

    return new WP_Error('wpcli_command_not_allowed', sprintf('WP-CLI command is not allowlisted: %s', $signature));
}

function openmira_find_wpcli_binary(): string
{
    foreach ([ABSPATH . 'vendor/bin/wp', dirname(path: __DIR__, levels: 2) . '/vendor/bin/wp'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $output = [];
    $exit_code = 1;
    exec(command: 'command -v wp 2>/dev/null', output: $output, result_code: $exit_code);
    $binary = $exit_code === 0 ? trim($output[0] ?? '') : '';

    return $binary !== '' && is_executable($binary) ? $binary : '';
}

/**
 * @param list<string> $args
 */
function openmira_build_wpcli_command(string $binary, array $args, int $timeout): string
{
    $escaped = array_map(static fn(string $arg): string => escapeshellarg($arg), $args);
    $has_path = count(array_filter($args, static fn(string $arg): bool => str_starts_with($arg, '--path='))) > 0;
    if (!$has_path) {
        $escaped[] = '--path=' . escapeshellarg(ABSPATH);
    }
    $has_allow_root = in_array('--allow-root', $args, strict: true);
    if (!$has_allow_root && openmira_wpcli_is_root_user() && openmira_wpcli_allow_root_enabled()) {
        $escaped[] = '--allow-root';
    }

    return 'timeout ' . (int) $timeout . ' ' . escapeshellarg($binary) . ' ' . implode(' ', $escaped);
}

/**
 * Refuse WP-CLI execution as root unless explicitly allowed.
 */
function openmira_refuse_wpcli_root_unless_allowed(): bool|WP_Error
{
    if (!openmira_wpcli_is_root_user()) {
        return true;
    }
    if (openmira_wpcli_allow_root_enabled()) {
        return true;
    }

    return new WP_Error(
        'wpcli_root_refused',
        'Refusing to run WP-CLI as root. Define OPENMIRA_WPCLI_ALLOW_ROOT to allow this explicitly.',
    );
}

/**
 * Return whether the current PHP process is running as root.
 */
function openmira_wpcli_is_root_user(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * Return whether Open Mira is explicitly allowed to invoke WP-CLI as root.
 */
function openmira_wpcli_allow_root_enabled(): bool
{
    $allow_root = defined('OPENMIRA_WPCLI_ALLOW_ROOT') ? constant('OPENMIRA_WPCLI_ALLOW_ROOT') : false;

    return $allow_root === true || $allow_root === 1 || $allow_root === '1';
}

/**
 * Start an async WP-CLI job.
 *
 * @param list<string>          $args
 * @param array<string, mixed>  $policy
 * @return array<string, mixed>|WP_Error
 */
function openmira_start_wpcli_job(array $args, array $policy, string $command, int $timeout): array|WP_Error
{
    openmira_cleanup_old_wpcli_jobs();
    $ready = openmira_ensure_wpcli_jobs_dir();
    if (is_wp_error($ready)) {
        return $ready;
    }

    $job_id = 'wpcli_' . gmdate('YmdHis') . '_' . strtolower(wp_generate_password(length: 8, special_chars: false));
    $job_dir = openmira_wpcli_job_dir($job_id);
    if (!wp_mkdir_p($job_dir)) {
        return new WP_Error('wpcli_job_dir_failed', 'Could not create WP-CLI job directory.', ['job_id' => $job_id]);
    }

    $log_path = $job_dir . '/output.log';
    $done_path = $job_dir . '/done.json';
    $runner_path = $job_dir . '/run.sh';
    $php_binary = PHP_BINARY;
    $done_writer = <<<'PHP'
        $payload = [
            'exit_code' => (int) $argv[2],
            'finished_at' => gmdate('c'),
        ];
        file_put_contents($argv[1], json_encode($payload) . PHP_EOL);
        PHP;
    $runner =
        "#!/bin/sh\n"
        . $command
        . ' > '
        . escapeshellarg($log_path)
        . " 2>&1\n"
        . "exit_code=$?\n"
        . escapeshellarg($php_binary)
        . ' -r '
        . escapeshellarg($done_writer)
        . ' '
        . escapeshellarg($done_path)
        . " \"\$exit_code\"\n";

    if (file_put_contents($runner_path, $runner, LOCK_EX) === false) {
        return new WP_Error('wpcli_job_write_failed', 'Could not write WP-CLI job runner.', ['job_id' => $job_id]);
    }
    chmod(filename: $runner_path, permissions: 0700);

    $meta = [
        'job_id' => $job_id,
        'status' => 'running',
        'args' => $args,
        'policy' => $policy,
        'command' => $command,
        'timeout_seconds' => $timeout,
        'started_at' => gmdate('c'),
        'pid' => 0,
    ];
    openmira_write_wpcli_job_json($job_dir . '/meta.json', $meta);

    $output = [];
    $exit_code = 1;
    exec(
        command: 'sh ' . escapeshellarg($runner_path) . ' >/dev/null 2>&1 & echo $!',
        output: $output,
        result_code: $exit_code,
    );
    $pid = $exit_code === 0 ? (int) trim($output[0] ?? '0') : 0;
    if ($pid < 1) {
        return new WP_Error('wpcli_job_start_failed', 'Could not start async WP-CLI job.', ['job_id' => $job_id]);
    }

    $meta['pid'] = $pid;
    openmira_write_wpcli_job_json($job_dir . '/meta.json', $meta);

    return [
        'ok' => true,
        'mode' => 'async',
        'job_id' => $job_id,
        'status' => 'running',
        'pid' => $pid,
        'args' => $args,
        'policy' => $policy,
        'timeout_seconds' => $timeout,
        'log_offset' => 0,
        'poll_ability' => 'openmira/get-wpcli-job',
    ];
}

/**
 * Ensure async WP-CLI job storage exists.
 */
function openmira_ensure_wpcli_jobs_dir(): bool|WP_Error
{
    if (is_dir(OPENMIRA_WPCLI_JOBS_DIR) || wp_mkdir_p(OPENMIRA_WPCLI_JOBS_DIR)) {
        return true;
    }

    return new WP_Error('wpcli_jobs_dir_failed', 'Could not create WP-CLI jobs directory.');
}

/**
 * Return a job directory path for an ID.
 */
function openmira_wpcli_job_dir(string $job_id): string
{
    return trailingslashit(OPENMIRA_WPCLI_JOBS_DIR) . $job_id;
}

/**
 * Write a JSON job metadata file.
 *
 * @param array<string, mixed> $payload
 */
function openmira_write_wpcli_job_json(string $path, array $payload): void
{
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT);
    file_put_contents($path, (is_string($json) ? $json : '{}') . "\n", LOCK_EX);
}

/**
 * Read a JSON job metadata file.
 *
 * @return array<array-key, mixed>|WP_Error
 */
function openmira_read_wpcli_job_json(string $path): array|WP_Error
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($raw)) {
        return new WP_Error('wpcli_job_read_failed', 'Could not read WP-CLI job metadata.');
    }
    $data = json_decode($raw, associative: true);
    if (!is_array($data)) {
        return new WP_Error('wpcli_job_json_invalid', 'WP-CLI job metadata is invalid JSON.');
    }

    return $data;
}

/**
 * Return whether a process still appears to be alive.
 */
function openmira_wpcli_process_is_running(int $pid): bool
{
    if ($pid < 1 || !function_exists('posix_kill')) {
        return false;
    }

    return posix_kill($pid, 0);
}

/**
 * Remove async jobs older than 24 hours.
 */
function openmira_cleanup_old_wpcli_jobs(): void
{
    if (!is_dir(OPENMIRA_WPCLI_JOBS_DIR)) {
        return;
    }

    $cutoff = time() - 86_400;
    $job_dirs = glob(OPENMIRA_WPCLI_JOBS_DIR . '/wpcli_*');
    foreach (is_array($job_dirs) ? $job_dirs : [] as $job_dir) {
        $modified = is_dir($job_dir) ? filemtime($job_dir) : false;
        if ($modified === false || $modified >= $cutoff) {
            continue;
        }
        openmira_remove_wpcli_job_dir($job_dir);
    }
}

/**
 * Recursively remove a job directory.
 */
function openmira_remove_wpcli_job_dir(string $dir): void
{
    $paths = glob(trailingslashit($dir) . '*');
    foreach (is_array($paths) ? $paths : [] as $path) {
        if (is_dir($path)) {
            openmira_remove_wpcli_job_dir($path);
            continue;
        }
        unlink($path);
    }
    rmdir($dir);
}
