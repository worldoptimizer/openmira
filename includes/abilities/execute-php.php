<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Execute PHP code.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_EXECUTE_PHP_WINDOW_SECONDS = 60;

const OPENMIRA_EXECUTE_PHP_MAX_CALLS_PER_WINDOW = 20;

const OPENMIRA_EXECUTE_PHP_MAX_MEMORY_DELTA_BYTES = 16_777_216;

wp_register_ability('openmira/execute-php', [
    'label' => __('Execute PHP Code', domain: 'open-mira'),
    'description' => __(
        'Executes PHP code on the WordPress server. The full WordPress environment is available including $wpdb, all WordPress functions, and loaded plugins. Returns the return value, any echoed output, and captured warnings/notices.',
        domain: 'open-mira',
    ),
    'category' => 'code-execution',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute. Do NOT include <?php tags. Use "return $value;" to return data for inspection.',
                'minLength' => 1,
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean', 'description' => 'Whether the code executed without throwing.'],
            'return_value' => ['description' => 'The value returned by the evaluated code.'],
            'output' => ['type' => 'string', 'description' => 'Any output captured via echo/print.'],
            'errors' => [
                'type' => 'array',
                'description' => 'PHP warnings, notices, and deprecations captured during execution.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'line' => ['type' => 'integer'],
                    ],
                ],
            ],
            'error_message' => ['type' => 'string', 'description' => 'Error message if execution failed.'],
            'error_class' => [
                'type' => 'string',
                'description' => 'Exception/Error class name if execution failed.',
            ],
            'execution_time_ms' => [
                'type' => 'number',
                'description' => 'Wall-clock execution time in milliseconds.',
            ],
        ],
    ],
    'execute_callback' => 'openmira_execute_php',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'IMPORTANT SAFETY RULES:',
                '- Never call exit() or die() — this kills the entire PHP process.',
                '- Never create infinite loops — there is a 30-second time limit.',
                '- Do NOT include <?php opening tags.',
                '- Use "return $value;" to inspect values (the return value is captured).',
                '- Any echo/print output is captured separately in the "output" field.',
                '- The full WordPress API is available: $wpdb, get_option(), WP_Query, etc.',
                '- All loaded plugin APIs are available.',
                '- Execution has a ' . OPENMIRA_MAX_EXECUTION_TIME . ' second time limit.',
                '- For hook inspection, prefer openmira/find-hook-registrants over dumping $wp_filter manually.',
                '  Runtime callbacks may be objects or closures that do not cast safely to strings.',
                '- For callbacks guarded by is_singular(), in_the_loop(), or is_main_query(),',
                '  prefer probe-url or screenshot-url verification; eval context can produce false negatives.',
                '',
                'SANDBOX CONTEXT:',
                '- To create persistent PHP functionality, write files to the sandbox',
                '  (wp-content/openmira-sandbox/) using the write-file ability.',
                '- Code executed here via eval() is temporary and does not persist across requests.',
                '- Do NOT use eval to require/include files that may have errors — use write-file',
                '  to persist them instead.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute PHP code.
 *
 * @param array $input Input with 'code' key.
 * @return array|WP_Error
 */
function openmira_execute_php($input)
{
    $mode_error = openmira_require_act_mode('openmira/execute-php');
    if (is_wp_error($mode_error)) {
        return $mode_error;
    }

    $rate_error = openmira_execute_php_check_rate_limit();
    if (is_wp_error($rate_error)) {
        return $rate_error;
    }

    $code = (string) $input['code'];
    $errors = [];

    // Save and set time limit.
    $original_time_limit = (int) ini_get('max_execution_time');
    set_time_limit(OPENMIRA_MAX_EXECUTION_TIME);

    // Set up error handler to capture warnings/notices.
    $error_types = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    set_error_handler(static function ($errno, $errstr, $errfile, $errline) use (&$errors, $error_types) {
        $errors[] = [
            'type' => $error_types[$errno] ?? 'Unknown (' . (int) $errno . ')',
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ];
        return true;
    });

    ob_start();
    $start = microtime(true);
    $start_memory = memory_get_usage(real_usage: true);

    $return_value = null;
    $success = true;
    $error_message = null;
    $error_class = null;

    try {
        // @mago-ignore lint:no-eval
        /** @var mixed $return_value */
        $return_value = eval($code);
    } catch (\Throwable $e) {
        $success = false;
        $error_message = $e->getMessage();
        $error_class = get_class($e);
    }

    $execution_time_ms = round(num: (microtime(true) - $start) * 1000, precision: 2);
    $memory_delta_bytes = max(0, memory_get_usage(real_usage: true) - $start_memory);
    $output = ob_get_clean();

    restore_error_handler();
    set_time_limit($original_time_limit);

    // Ensure return value is JSON-serializable.
    if ($return_value !== null && json_encode($return_value) === false) {
        $return_value = print_r(value: $return_value, return: true);
    }

    $result = [
        'success' => $success,
        'return_value' => $return_value,
        'output' => $output,
        'errors' => $errors,
        'execution_time_ms' => $execution_time_ms,
        'memory_delta_bytes' => $memory_delta_bytes,
        'runaway_guard' => openmira_execute_php_guard_public_state(),
    ];

    return openmira_execute_php_finalize_result(
        result: $result,
        memory_delta_bytes: $memory_delta_bytes,
        error_message: $error_message,
        error_class: $error_class,
    );
}

/**
 * Add throwable and runaway-guard details to an execute-php response.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function openmira_execute_php_finalize_result(
    array $result,
    int $memory_delta_bytes,
    ?string $error_message,
    ?string $error_class,
): array {
    $memory_error = openmira_execute_php_memory_delta_error($memory_delta_bytes);
    if (is_wp_error($memory_error)) {
        $result['success'] = false;
        $result['error_message'] = $memory_error->get_error_message();
        $result['error_class'] = 'OpenMiraRunawayGuard';
        $result['runaway_guard_error'] = [
            'code' => $memory_error->get_error_code(),
            'data' => $memory_error->get_error_data(),
        ];
    }

    if ($error_message !== null) {
        $result['error_message'] = $error_message;
        $result['error_class'] = $error_class;
    }

    return $result;
}

/**
 * Enforce a per-user execute-php call budget.
 */
function openmira_execute_php_check_rate_limit(): bool|WP_Error
{
    $window_seconds = openmira_execute_php_window_seconds();
    $max_calls = openmira_execute_php_max_calls_per_window();
    $key = openmira_execute_php_rate_limit_key();

    // @mago-expect analysis:mixed-assignment
    $state = get_transient($key);
    if (!is_array($state)) {
        $state = [
            'count' => 0,
            'window_started_at' => time(),
        ];
    }

    $count = (int) ($state['count'] ?? 0);
    $window_started_at = (int) ($state['window_started_at'] ?? time());
    if ((time() - $window_started_at) >= $window_seconds) {
        $count = 0;
        $window_started_at = time();
    }

    if ($count >= $max_calls) {
        return new WP_Error(
            'openmira_execute_php_rate_limited',
            sprintf('execute-php exceeded %d calls in %d seconds for this user.', $max_calls, $window_seconds),
            [
                'count' => $count,
                'max_calls' => $max_calls,
                'window_seconds' => $window_seconds,
                'retry_after_seconds' => max(1, $window_seconds - (time() - $window_started_at)),
            ],
        );
    }

    set_transient(
        $key,
        [
            'count' => $count + 1,
            'window_started_at' => $window_started_at,
        ],
        $window_seconds,
    );

    return true;
}

/**
 * Return a structured memory guard error when a single eval grows memory too much.
 */
function openmira_execute_php_memory_delta_error(int $memory_delta_bytes): ?WP_Error
{
    $max_memory_delta_bytes = openmira_execute_php_max_memory_delta_bytes();
    if ($memory_delta_bytes <= $max_memory_delta_bytes) {
        return null;
    }

    return new WP_Error(
        'openmira_execute_php_memory_delta_exceeded',
        sprintf(
            'execute-php used %d bytes of additional memory, above the %d byte per-call limit.',
            $memory_delta_bytes,
            $max_memory_delta_bytes,
        ),
        [
            'memory_delta_bytes' => $memory_delta_bytes,
            'max_memory_delta_bytes' => $max_memory_delta_bytes,
        ],
    );
}

/**
 * Public guard state returned with successful execute-php calls.
 *
 * @return array<string, int>
 */
function openmira_execute_php_guard_public_state(): array
{
    // @mago-expect analysis:mixed-assignment
    $state = get_transient(openmira_execute_php_rate_limit_key());
    $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

    return [
        'count' => $count,
        'max_calls' => openmira_execute_php_max_calls_per_window(),
        'window_seconds' => openmira_execute_php_window_seconds(),
        'max_memory_delta_bytes' => openmira_execute_php_max_memory_delta_bytes(),
    ];
}

/**
 * Rate-limit transient key for the current user.
 */
function openmira_execute_php_rate_limit_key(): string
{
    return 'openmira_execute_php_rate_' . get_current_user_id();
}

/**
 * Filterable execute-php rate window.
 */
function openmira_execute_php_window_seconds(): int
{
    return max(1, (int) apply_filters('openmira_execute_php_window_seconds', OPENMIRA_EXECUTE_PHP_WINDOW_SECONDS));
}

/**
 * Filterable execute-php call budget.
 */
function openmira_execute_php_max_calls_per_window(): int
{
    return max(
        1,
        (int) apply_filters('openmira_execute_php_max_calls_per_window', OPENMIRA_EXECUTE_PHP_MAX_CALLS_PER_WINDOW),
    );
}

/**
 * Filterable execute-php per-call memory delta budget.
 */
function openmira_execute_php_max_memory_delta_bytes(): int
{
    return max(
        1,
        (int) apply_filters('openmira_execute_php_max_memory_delta_bytes', OPENMIRA_EXECUTE_PHP_MAX_MEMORY_DELTA_BYTES),
    );
}
