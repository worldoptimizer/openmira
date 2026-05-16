<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Lint one project file.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/lint-file', [
    'label' => __('Lint File', domain: 'open-mira'),
    'description' => __(
        'Runs PHP syntax validation and PHPCS/WPCS when a project phpcs binary is available.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'File path relative to WordPress root.', 'minLength' => 1],
            'standard' => ['type' => 'string', 'description' => 'Optional PHPCS standard override.', 'default' => ''],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_lint_file',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Run after PHP/CSS/JS edits when validating generated code.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Lint one file.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_lint_file(array $input): array|WP_Error
{
    $resolved = openmira_resolve_path(path: (string) ($input['path'] ?? ''), must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }
    if (!is_file($resolved) || !is_readable($resolved)) {
        return new WP_Error('not_readable', sprintf('File is not readable: %s', openmira_display_path($resolved)));
    }

    $syntax = strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'php'
        ? openmira_php_lint_file($resolved)
        : ['ok' => true, 'exit_code' => 0, 'output' => 'Skipped: non-PHP file.', 'command' => 'none'];

    $phpcs = openmira_run_phpcs($resolved, trim((string) ($input['standard'] ?? '')));
    $diagnostics = array_merge(
        openmira_lint_syntax_diagnostics($syntax, $resolved),
        openmira_lint_phpcs_diagnostics($phpcs),
    );

    return [
        'path' => openmira_display_path($resolved),
        'ok' => $syntax['ok'] === true && ($phpcs['detected'] === false || $phpcs['exit_code'] === 0),
        'syntax' => $syntax,
        'phpcs' => $phpcs,
        'diagnostics' => $diagnostics,
    ];
}

/**
 * Run PHPCS if available.
 *
 * @return array<string, mixed>
 */
function openmira_run_phpcs(string $resolved, string $standard): array
{
    $binary = openmira_find_phpcs_binary($resolved);
    if ($binary === '') {
        return [
            'detected' => false,
            'ok' => true,
            'exit_code' => 0,
            'command' => '',
            'standard' => '',
            'output' => '',
        ];
    }

    $standard = $standard !== '' ? $standard : openmira_find_phpcs_standard($resolved);
    $command = escapeshellarg($binary) . ' --report=json';
    if ($standard !== '') {
        $command .= ' --standard=' . escapeshellarg($standard);
    }
    $command .= ' ' . escapeshellarg($resolved);

    $output = [];
    $exit_code = 1;
    exec(command: $command . ' 2>&1', output: $output, result_code: $exit_code);
    $raw = implode("\n", $output);

    return [
        'detected' => true,
        'ok' => $exit_code === 0,
        'exit_code' => $exit_code,
        'command' => $command,
        'standard' => $standard,
        'output' => $raw,
        'report' => openmira_decode_phpcs_report($raw),
    ];
}

function openmira_find_phpcs_binary(string $resolved): string
{
    $directories = array_filter(
        array_unique([
            dirname($resolved),
            get_stylesheet_directory(),
            get_template_directory(),
            WP_PLUGIN_DIR,
            ABSPATH,
            dirname(path: __DIR__, levels: 2),
        ]),
        static fn(string $directory): bool => $directory !== '',
    );

    foreach ($directories as $directory) {
        $current = $directory;
        while ($current !== '' && $current !== dirname($current)) {
            $candidate = $current . '/vendor/bin/phpcs';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
            $current = dirname($current);
        }
    }

    return '';
}

function openmira_find_phpcs_standard(string $resolved): string
{
    $current = dirname($resolved);
    while ($current !== '' && $current !== dirname($current)) {
        foreach (['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist'] as $file) {
            if (is_file($current . '/' . $file)) {
                return $current . '/' . $file;
            }
        }
        $current = dirname($current);
    }

    return '';
}

/**
 * @return array<array-key, mixed>
 */
function openmira_decode_phpcs_report(string $raw): array
{
    // @mago-expect analysis:mixed-assignment
    $decoded = json_decode($raw, associative: true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array{ok: bool, exit_code: int, output: string, command: string} $syntax
 * @return list<array<string, mixed>>
 */
function openmira_lint_syntax_diagnostics(array $syntax, string $resolved): array
{
    if ($syntax['ok']) {
        return [];
    }

    return [[
        'source' => 'php-syntax',
        'severity' => 'error',
        'path' => openmira_display_path($resolved),
        'line' => 0,
        'column' => 0,
        'message' => $syntax['output'],
    ]];
}

/**
 * @param array<array-key, mixed> $phpcs
 * @return list<array<string, mixed>>
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_lint_phpcs_diagnostics(array $phpcs): array
{
    if (($phpcs['detected'] ?? false) !== true || !is_array($phpcs['report'] ?? null)) {
        return [];
    }

    $diagnostics = [];
    // @mago-expect analysis:mixed-assignment
    $files = $phpcs['report']['files'] ?? [];
    if (!is_array($files)) {
        return [];
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($files as $path => $file_report) {
        if (!is_array($file_report)) {
            continue;
        }
        // @mago-expect analysis:mixed-assignment
        $messages = $file_report['messages'] ?? [];
        if (!is_array($messages)) {
            continue;
        }
        // @mago-expect analysis:mixed-assignment
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $diagnostics[] = [
                'source' => 'phpcs',
                'severity' => (string) ($message['type'] ?? 'warning'),
                'path' => openmira_display_path((string) $path),
                'line' => (int) ($message['line'] ?? 0),
                'column' => (int) ($message['column'] ?? 0),
                'message' => (string) ($message['message'] ?? ''),
                'source_code' => (string) ($message['source'] ?? ''),
            ];
        }
    }

    return $diagnostics;
}
