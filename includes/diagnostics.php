<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Request-scoped diagnostics for ability execution.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_LAST_FATAL_TRANSIENT = 'openmira_last_fatal_error_';

/**
 * Start diagnostics capture for a REST ability run.
 */
function openmira_maybe_start_ability_diagnostics(
    mixed $result,
    WP_REST_Server $server,
    WP_REST_Request $request,
): mixed {
    unset($server);

    if (!openmira_is_ability_run_request($request)) {
        return $result;
    }

    openmira_ability_diagnostics_context([
        'started_at' => microtime(as_float: true),
        'route' => $request->get_route(),
        'debug_log' => openmira_debug_log_path(),
        'debug_log_offset' => openmira_debug_log_size(),
    ]);

    return $result;
}

/**
 * Append diagnostics to successful REST ability responses.
 */
function openmira_append_ability_diagnostics(
    WP_REST_Response $response,
    WP_REST_Server $server,
    WP_REST_Request $request,
): WP_REST_Response {
    unset($server);

    if (!openmira_is_ability_run_request($request)) {
        return $response;
    }

    $diagnostics = openmira_collect_ability_diagnostics();
    if ($diagnostics === []) {
        return $response;
    }

    // @mago-expect analysis:mixed-assignment
    $data = $response->get_data();
    if (is_array($data)) {
        $data['_openmira_diagnostics'] = $diagnostics;
        $response->set_data($data);
    }

    return $response;
}

/**
 * Persist fatal errors from ability execution for the next response/request.
 */
function openmira_capture_shutdown_diagnostics(): void
{
    if (openmira_ability_diagnostics_context() === []) {
        return;
    }

    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int) $error['type'], $fatal_types, strict: true)) {
        return;
    }

    set_transient(
        openmira_last_fatal_key(),
        [
            'type' => (int) $error['type'],
            'message' => $error['message'],
            'file' => openmira_display_path($error['file']),
            'line' => (int) $error['line'],
            'captured_at' => gmdate('c'),
        ],
        10 * OPENMIRA_MINUTE_IN_SECONDS,
    );
}

function openmira_is_ability_run_request(WP_REST_Request $request): bool
{
    return (
        str_starts_with($request->get_route(), '/wp-abilities/v1/abilities/')
        && str_ends_with($request->get_route(), '/run')
    );
}

/**
 * @return array<string, mixed>
 */
function openmira_collect_ability_diagnostics(): array
{
    $diagnostics = [];
    $debug_lines = openmira_read_new_debug_log_lines();
    if ($debug_lines !== []) {
        $diagnostics['debug_log'] = $debug_lines;
    }

    // @mago-expect analysis:mixed-assignment
    $fatal = get_transient(openmira_last_fatal_key());
    if (is_array($fatal)) {
        $diagnostics['last_fatal'] = $fatal;
        delete_transient(openmira_last_fatal_key());
    }

    return $diagnostics;
}

/**
 * @return list<string>
 */
function openmira_read_new_debug_log_lines(): array
{
    $path = openmira_debug_log_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $context = openmira_ability_diagnostics_context();
    $offset = (int) ($context['debug_log_offset'] ?? 0);
    $size = filesize($path);
    if (!is_int($size) || $size <= $offset) {
        return [];
    }

    $length = min(20_000, $size - $offset);
    $handle = fopen(filename: $path, mode: 'rb');
    if (!is_resource($handle)) {
        return [];
    }

    fseek($handle, max(0, $size - $length));
    $content = stream_get_contents($handle);
    fclose($handle);
    if (!is_string($content) || $content === '') {
        return [];
    }

    $lines = preg_split(pattern: '/\\R/', subject: trim($content));
    if (!is_array($lines)) {
        return [];
    }

    return array_values(array_slice(array_filter($lines, static fn(string $line): bool => $line !== ''), offset: -50));
}

function openmira_debug_log_path(): string
{
    $debug_log = defined('WP_DEBUG_LOG') ? constant('WP_DEBUG_LOG') : null;
    if (is_string($debug_log) && $debug_log !== '') {
        return $debug_log;
    }

    if ($debug_log === true) {
        return WP_CONTENT_DIR . '/debug.log';
    }

    return WP_CONTENT_DIR . '/debug.log';
}

function openmira_debug_log_size(): int
{
    $path = openmira_debug_log_path();
    $size = $path !== '' && is_file($path) ? filesize($path) : 0;

    return is_int($size) ? $size : 0;
}

function openmira_last_fatal_key(): string
{
    return OPENMIRA_LAST_FATAL_TRANSIENT . get_current_user_id();
}

add_filter(
    hook_name: 'rest_pre_dispatch',
    callback: 'openmira_maybe_start_ability_diagnostics',
    priority: 10,
    accepted_args: 3,
);
add_filter(
    hook_name: 'rest_post_dispatch',
    callback: 'openmira_append_ability_diagnostics',
    priority: 10,
    accepted_args: 3,
);
register_shutdown_function('openmira_capture_shutdown_diagnostics');

/**
 * Store request-local diagnostics context without using $GLOBALS.
 *
 * @param array<string, mixed>|null $context
 * @return array<string, mixed>
 */
function openmira_ability_diagnostics_context(?array $context = null): array
{
    static $current = [];

    if ($context !== null) {
        $current = $context;
    }

    return $current;
}
