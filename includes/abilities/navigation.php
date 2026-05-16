<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: WordPress-aware code navigation.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/find-hook-callers', [
    'label' => __('Find Hook Callers', domain: 'open-mira'),
    'description' => __('Finds PHP files that fire a WordPress action or filter hook.', domain: 'open-mira'),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'hook' => ['type' => 'string', 'description' => 'Hook name.', 'minLength' => 1],
            'max_files' => ['type' => 'integer', 'default' => 500, 'minimum' => 1, 'maximum' => 2000],
        ],
        'required' => ['hook'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_find_hook_callers',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use before adding callbacks or changing hook contracts.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/find-hook-registrants', [
    'label' => __('Find Hook Registrants', domain: 'open-mira'),
    'description' => __(
        'Finds callbacks registered to a WordPress action or filter, using static AST scan plus runtime wp_filter inspection.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'hook' => ['type' => 'string', 'description' => 'Hook name.', 'minLength' => 1],
            'include_runtime' => ['type' => 'boolean', 'default' => true],
            'max_files' => ['type' => 'integer', 'default' => 500, 'minimum' => 1, 'maximum' => 2000],
        ],
        'required' => ['hook'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_find_hook_registrants',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use to understand what currently responds to a hook before editing behavior. Prefer this over manual $wp_filter dumps in execute-php because callbacks may be objects or closures.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

wp_register_ability('openmira/resolve-template', [
    'label' => __('Resolve Template', domain: 'open-mira'),
    'description' => __(
        'Resolves the WordPress template hierarchy for a URL, post, post type archive, or template type.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'Optional URL on this site.', 'default' => ''],
            'post_id' => ['type' => 'integer', 'description' => 'Optional post ID.', 'minimum' => 1],
            'post_type' => [
                'type' => 'string',
                'description' => 'Optional post type for archive/single fallback.',
                'default' => '',
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'Fallback template type.',
                'enum' => ['frontpage', 'home', 'page', 'single', 'archive', 'index'],
                'default' => 'index',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_resolve_template_ability',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use before editing a theme file for a page, post, archive, homepage, or route.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Find hook callers.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_find_hook_callers(array $input): array|WP_Error
{
    $hook = (string) ($input['hook'] ?? '');
    if ($hook === '') {
        return new WP_Error('missing_hook', 'hook is required.');
    }

    $max_files = max(1, min(2000, (int) ($input['max_files'] ?? 500)));
    $scan = openmira_navigation_scan_hooks($hook, $max_files);

    return [
        'hook' => $hook,
        'static' => [
            'files_scanned' => $scan['files_scanned'],
            'parse_errors' => $scan['parse_errors'],
            'callers' => $scan['callers'],
        ],
    ];
}

/**
 * Find hook registrants.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_find_hook_registrants(array $input): array|WP_Error
{
    $hook = (string) ($input['hook'] ?? '');
    if ($hook === '') {
        return new WP_Error('missing_hook', 'hook is required.');
    }

    $max_files = max(1, min(2000, (int) ($input['max_files'] ?? 500)));
    $include_runtime = ($input['include_runtime'] ?? true) !== false;
    $scan = openmira_navigation_scan_hooks($hook, $max_files);

    return [
        'hook' => $hook,
        'static' => [
            'files_scanned' => $scan['files_scanned'],
            'parse_errors' => $scan['parse_errors'],
            'registrants' => $scan['registrants'],
        ],
        'runtime' => $include_runtime ? openmira_navigation_runtime_registrants($hook) : [],
    ];
}

/**
 * Resolve a theme template.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_resolve_template_ability(array $input): array
{
    $context = openmira_template_context($input);
    $templates = openmira_template_hierarchy($context);
    $type = (string) $context['template_type'];
    $php_template = locate_template($templates);
    $block_template = function_exists('resolve_block_template')
        ? resolve_block_template($type, $templates, $php_template)
        : null;

    return [
        'context' => $context,
        'theme' => [
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
            'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
        ],
        'hierarchy' => array_map('openmira_template_candidate_status', $templates),
        'resolved' => openmira_template_resolved_payload($type, $php_template, $block_template),
    ];
}

/**
 * Scan active project PHP files for hook usage.
 *
 * @return array{files_scanned: int, parse_errors: list<array<string, mixed>>, callers: list<array<string, mixed>>, registrants: list<array<string, mixed>>}
 */
function openmira_navigation_scan_hooks(string $hook, int $max_files): array
{
    $parser = (new PhpParser\ParserFactory())->createForHostVersion();
    $files = openmira_navigation_php_files($max_files);
    $callers = [];
    $registrants = [];
    $parse_errors = [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            continue;
        }

        try {
            $nodes = $parser->parse($content) ?? [];
        } catch (Throwable $throwable) {
            $parse_errors[] = [
                'path' => openmira_display_path($file),
                'message' => $throwable->getMessage(),
            ];
            continue;
        }

        openmira_navigation_collect_hooks($nodes, $file, $hook, $callers, $registrants);
    }

    return [
        'files_scanned' => count($files),
        'parse_errors' => $parse_errors,
        'callers' => $callers,
        'registrants' => $registrants,
    ];
}

/**
 * Return active theme/plugin PHP files.
 *
 * @return list<string>
 */
function openmira_navigation_php_files(int $max_files): array
{
    openmira_project_map_load_plugin_functions();
    $directories = [];
    $base_directories = [
        get_stylesheet_directory(),
        get_template_directory(),
        WP_PLUGIN_DIR,
        OPENMIRA_SANDBOX_DIR,
    ];
    foreach ($base_directories as $directory) {
        if ($directory === '' || !is_dir($directory)) {
            continue;
        }
        $directories[] = $directory;
    }
    $mu_plugin_dir = defined('WPMU_PLUGIN_DIR') ? (string) constant('WPMU_PLUGIN_DIR') : '';
    if ($mu_plugin_dir !== '' && is_dir($mu_plugin_dir)) {
        $directories[] = $mu_plugin_dir;
    }
    $directories = array_values(array_unique($directories));

    $files = openmira_project_map_collect_files(directories: $directories, limit: $max_files, focus_path: '');
    $php_files = [];
    foreach ($files as $file) {
        $absolute = openmira_project_map_absolute_path((string) ($file['path'] ?? ''));
        if ($absolute !== '' && is_file($absolute) && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
            $php_files[] = $absolute;
        }
    }

    return array_slice(array_values(array_unique($php_files)), offset: 0, length: $max_files);
}

/**
 * Walk AST nodes and collect matching hook calls.
 *
 * @param array<array-key, mixed>       $nodes
 * @param list<array<string, mixed>>    $callers
 * @param list<array<string, mixed>>    $registrants
 */
function openmira_navigation_collect_hooks(
    array $nodes,
    string $file,
    string $hook,
    array &$callers,
    array &$registrants,
): void {
    // @mago-expect analysis:mixed-assignment
    foreach ($nodes as $node) {
        if (!$node instanceof PhpParser\Node) {
            continue;
        }

        if ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name) {
            $function = $node->name->toString();
            $hook_name = openmira_navigation_string_arg(node: $node, index: 0);
            if ($hook_name === $hook) {
                if (in_array(
                    $function,
                    ['do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array'],
                    strict: true,
                )) {
                    $callers[] = openmira_navigation_hook_call_payload($node, $file, $function, $hook_name);
                }
                if (in_array($function, ['add_action', 'add_filter'], strict: true)) {
                    $registrants[] = openmira_navigation_hook_registration_payload($node, $file, $function, $hook_name);
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            // @mago-expect analysis:mixed-assignment
            // @mago-expect analysis:string-member-selector
            $child = $node->$name;
            if ($child instanceof PhpParser\Node) {
                openmira_navigation_collect_hooks([$child], $file, $hook, $callers, $registrants);
                continue;
            }
            if (is_array($child)) {
                openmira_navigation_collect_hooks($child, $file, $hook, $callers, $registrants);
            }
        }
    }
}

/**
 * Return a string argument value when statically known.
 */
function openmira_navigation_string_arg(PhpParser\Node\Expr\FuncCall $node, int $index): string
{
    $argument = $node->args[$index] ?? null;
    $arg = $argument instanceof PhpParser\Node\Arg ? $argument->value : null;
    if ($arg instanceof PhpParser\Node\Scalar\String_) {
        return $arg->value;
    }

    return '';
}

/**
 * Return an integer argument value when statically known.
 */
function openmira_navigation_int_arg(PhpParser\Node\Expr\FuncCall $node, int $index, int $default): int
{
    $argument = $node->args[$index] ?? null;
    $arg = $argument instanceof PhpParser\Node\Arg ? $argument->value : null;
    if ($arg instanceof PhpParser\Node\Scalar\Int_) {
        return $arg->value;
    }

    return $default;
}

/**
 * @return array<string, mixed>
 */
function openmira_navigation_hook_call_payload(
    PhpParser\Node\Expr\FuncCall $node,
    string $file,
    string $function,
    string $hook,
): array {
    return [
        'hook' => $hook,
        'kind' => str_contains($function, 'filter') ? 'filter' : 'action',
        'function' => $function,
        'path' => openmira_display_path($file),
        'line' => $node->getStartLine(),
    ];
}

/**
 * @return array<string, mixed>
 */
function openmira_navigation_hook_registration_payload(
    PhpParser\Node\Expr\FuncCall $node,
    string $file,
    string $function,
    string $hook,
): array {
    return [
        'hook' => $hook,
        'kind' => $function === 'add_filter' ? 'filter' : 'action',
        'function' => $function,
        'callback' => openmira_navigation_callback_arg(openmira_navigation_arg_value(node: $node, index: 1)),
        'priority' => openmira_navigation_int_arg(node: $node, index: 2, default: 10),
        'accepted_args' => openmira_navigation_int_arg(node: $node, index: 3, default: 1),
        'path' => openmira_display_path($file),
        'line' => $node->getStartLine(),
    ];
}

function openmira_navigation_callback_arg(mixed $node): string
{
    if ($node instanceof PhpParser\Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof PhpParser\Node\Expr\Closure) {
        return 'closure';
    }
    if ($node instanceof PhpParser\Node\Expr\Array_ && count($node->items) >= 2) {
        $first = $node->items[0]->value ?? null;
        $second = $node->items[1]->value ?? null;
        return openmira_navigation_callback_part($first) . '::' . openmira_navigation_callback_part($second);
    }

    return 'dynamic';
}

function openmira_navigation_arg_value(PhpParser\Node\Expr\FuncCall $node, int $index): mixed
{
    $argument = $node->args[$index] ?? null;

    return $argument instanceof PhpParser\Node\Arg ? $argument->value : null;
}

function openmira_navigation_callback_part(mixed $node): string
{
    if ($node instanceof PhpParser\Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof PhpParser\Node\Expr\ClassConstFetch && $node->class instanceof PhpParser\Node\Name) {
        return $node->class->toString();
    }
    if ($node instanceof PhpParser\Node\Expr\Variable && is_string($node->name)) {
        return '$' . $node->name;
    }

    return 'dynamic';
}

/**
 * Return runtime callbacks currently attached to a hook.
 *
 * @return list<array<string, mixed>>
 */
function openmira_navigation_runtime_registrants(string $hook): array
{
    // @mago-expect lint:no-global
    global $wp_filter;

    // @mago-expect analysis:mixed-assignment
    $wp_hook = $wp_filter[$hook] ?? null;
    if (!$wp_hook instanceof WP_Hook) {
        return [];
    }

    $items = [];
    $callback_groups = $wp_hook->callbacks;
    foreach ($callback_groups as $priority => $callbacks) {
        // @mago-expect analysis:mixed-assignment
        if (!is_array($callbacks)) {
            continue;
        }
        foreach ($callbacks as $callback) {
            if (!is_array($callback)) {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            $callable = $callback['function'] ?? null;
            $items[] = array_merge([
                'hook' => $hook,
                'priority' => (int) $priority,
                'accepted_args' => (int) ($callback['accepted_args'] ?? 1),
                'callback' => openmira_navigation_runtime_callback_label($callable),
            ], openmira_navigation_runtime_callback_source($callable));
        }
    }

    return $items;
}

function openmira_navigation_runtime_callback_label(mixed $callable): string
{
    if (is_string($callable)) {
        return $callable;
    }
    if ($callable instanceof Closure) {
        return 'closure';
    }
    if (is_array($callable) && count($callable) === 2) {
        $target = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
        return $target . '::' . (string) $callable[1];
    }

    return 'dynamic';
}

/**
 * @return array{path: string, line: int, source: string}
 */
function openmira_navigation_runtime_callback_source(mixed $callable): array
{
    try {
        if (is_string($callable) && function_exists($callable)) {
            $reflection = new ReflectionFunction($callable);
        } elseif ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);
        } elseif (
            is_array($callable)
            && count($callable) === 2
            && (is_object($callable[0]) || is_string($callable[0]))
        ) {
            $reflection = new ReflectionMethod($callable[0], (string) $callable[1]);
        } else {
            return ['path' => '', 'line' => 0, 'source' => 'dynamic'];
        }
    } catch (ReflectionException) {
        return ['path' => '', 'line' => 0, 'source' => 'unresolved'];
    }

    $file = $reflection->getFileName();

    $line = $reflection->getStartLine();

    return [
        'path' => is_string($file) ? openmira_display_path($file) : '',
        'line' => is_int($line) ? $line : 0,
        'source' => is_string($file) ? openmira_navigation_source_bucket($file) : 'internal',
    ];
}

function openmira_navigation_source_bucket(string $file): string
{
    $normalized = wp_normalize_path($file);
    if (str_starts_with($normalized, wp_normalize_path(get_stylesheet_directory()))) {
        return 'active_theme';
    }
    if (str_starts_with($normalized, wp_normalize_path(WP_PLUGIN_DIR))) {
        return 'plugin';
    }
    if (
        defined('WPMU_PLUGIN_DIR')
        && str_starts_with($normalized, wp_normalize_path((string) constant('WPMU_PLUGIN_DIR')))
    ) {
        return 'mu_plugin';
    }
    if (str_starts_with($normalized, wp_normalize_path(ABSPATH))) {
        return 'wordpress_core';
    }

    return 'external';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function openmira_template_context(array $input): array
{
    $url = (string) ($input['url'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 && $url !== '') {
        $post_id = url_to_postid($url);
    }

    // @mago-expect analysis:mixed-assignment
    $post = $post_id > 0 ? get_post($post_id) : null;
    $post_type = $post instanceof WP_Post ? $post->post_type : sanitize_key((string) ($input['post_type'] ?? ''));
    $template_type = sanitize_key((string) ($input['template_type'] ?? 'index'));
    if ($post instanceof WP_Post) {
        $template_type = $post->post_type === 'page' ? 'page' : 'single';
    } elseif ($post_type !== '') {
        $template_type = 'archive';
    }

    return [
        'url' => $url,
        'post_id' => $post instanceof WP_Post ? $post->ID : 0,
        'post_type' => $post_type,
        'post_name' => $post instanceof WP_Post ? $post->post_name : '',
        'template_type' => $template_type,
    ];
}

/**
 * @param array<string, mixed> $context
 * @return list<string>
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_template_hierarchy(array $context): array
{
    $type = (string) ($context['template_type'] ?? 'index');
    $post_id = (int) ($context['post_id'] ?? 0);
    $post_name = (string) ($context['post_name'] ?? '');
    $post_type = (string) ($context['post_type'] ?? '');
    $templates = [];

    if ($type === 'frontpage') {
        return ['front-page.php'];
    }
    if ($type === 'home') {
        return ['home.php', 'index.php'];
    }
    if ($type === 'page') {
        $custom = $post_id > 0 ? get_page_template_slug($post_id) : '';
        if (is_string($custom) && $custom !== '' && validate_file($custom) === 0) {
            $templates[] = $custom;
        }
        if ($post_name !== '') {
            $templates[] = 'page-' . $post_name . '.php';
        }
        if ($post_id > 0) {
            $templates[] = 'page-' . $post_id . '.php';
        }
        $templates[] = 'page.php';
        return $templates;
    }
    if ($type === 'single') {
        $custom = $post_id > 0 ? get_page_template_slug($post_id) : '';
        if (is_string($custom) && $custom !== '' && validate_file($custom) === 0) {
            $templates[] = $custom;
        }
        if ($post_type !== '' && $post_name !== '') {
            $templates[] = 'single-' . $post_type . '-' . $post_name . '.php';
        }
        if ($post_type !== '') {
            $templates[] = 'single-' . $post_type . '.php';
        }
        $templates[] = 'single.php';
        return $templates;
    }
    if ($type === 'archive') {
        if ($post_type !== '') {
            $templates[] = 'archive-' . $post_type . '.php';
        }
        $templates[] = 'archive.php';
        $templates[] = 'index.php';
        return $templates;
    }

    return ['index.php'];
}

/**
 * @return array<string, mixed>
 */
function openmira_template_candidate_status(string $candidate): array
{
    $php_path = locate_template([$candidate]);
    $slug = function_exists('_strip_template_file_suffix')
        ? _strip_template_file_suffix($candidate)
        : preg_replace(pattern: '/\\.php$/', replacement: '', subject: $candidate);
    $block_file = function_exists('_get_block_template_file') && is_string($slug)
        ? _get_block_template_file('wp_template', $slug)
        : null;

    return [
        'candidate' => $candidate,
        'php_path' => $php_path !== '' ? openmira_display_path($php_path) : '',
        'block_path' => is_array($block_file) ? openmira_display_path($block_file['path']) : '',
    ];
}

/**
 * @return array<string, mixed>
 */
function openmira_template_resolved_payload(string $type, string $php_template, mixed $block_template): array
{
    if ($block_template instanceof WP_Block_Template) {
        $block_file = function_exists('_get_block_template_file')
            ? _get_block_template_file('wp_template', $block_template->slug)
            : null;

        return [
            'type' => 'block',
            'template_type' => $type,
            'id' => $block_template->id,
            'slug' => $block_template->slug,
            'theme' => $block_template->theme,
            'source' => $block_template->source,
            'path' => is_array($block_file) ? openmira_display_path($block_file['path']) : '',
            'canvas' => openmira_display_path(ABSPATH . WPINC . '/template-canvas.php'),
        ];
    }

    return [
        'type' => $php_template !== '' ? 'php' : 'none',
        'template_type' => $type,
        'path' => $php_template !== '' ? openmira_display_path($php_template) : '',
    ];
}
