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
            'destructive' => false,
            'idempotent' => false,
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

    $timeout = max(1, min(120, (int) ($input['timeout_seconds'] ?? 30)));
    $command = openmira_build_wpcli_command($binary, $args, $timeout);
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

    return 'timeout ' . (int) $timeout . ' ' . escapeshellarg($binary) . ' ' . implode(' ', $escaped);
}
