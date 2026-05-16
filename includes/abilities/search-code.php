<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Search project code.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/search-code', [
    'label' => __('Search Code', domain: 'open-mira'),
    'description' => __(
        'Searches text files under a WordPress path with literal or regex matching, file globs, context lines, and bounded results.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Text or regex pattern to find.',
                'minLength' => 1,
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Directory or file path relative to WordPress root. Defaults to wp-content.',
                'default' => 'wp-content',
            ],
            'regex' => [
                'type' => 'boolean',
                'description' => 'Treat query as a PCRE regex body instead of literal text.',
                'default' => false,
            ],
            'case_sensitive' => [
                'type' => 'boolean',
                'description' => 'Use case-sensitive matching.',
                'default' => false,
            ],
            'file_globs' => [
                'type' => 'array',
                'description' => 'Filename globs to include, e.g. ["*.php", "*.js"]. Defaults to common WP code files.',
                'items' => ['type' => 'string'],
                'default' => ['*.php', '*.js', '*.jsx', '*.ts', '*.tsx', '*.css', '*.scss', '*.json', '*.html'],
            ],
            'context_lines' => [
                'type' => 'integer',
                'description' => 'Lines of context before and after each match.',
                'default' => 2,
                'minimum' => 0,
                'maximum' => 10,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum matches to return.',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 500,
            ],
            'max_file_bytes' => [
                'type' => 'integer',
                'description' => 'Skip files larger than this byte size.',
                'default' => 1_048_576,
                'minimum' => 1024,
                'maximum' => 10_485_760,
            ],
            'include_hidden' => [
                'type' => 'boolean',
                'description' => 'Include dotfiles and hidden directories.',
                'default' => false,
            ],
            'allow_broad_scan' => [
                'type' => 'boolean',
                'description' => 'Allow broad roots like wp-content or plugin/theme roots. Prefer a specific plugin, theme, sandbox, or file path.',
                'default' => false,
            ],
            'max_candidate_files' => [
                'type' => 'integer',
                'description' => 'Refuse directory searches that would scan more candidate files than this limit.',
                'default' => 750,
                'minimum' => 1,
                'maximum' => 5000,
            ],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_search_code',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use before listing/reading broad plugin or theme trees. Matched files are read-tracked for safe follow-up edit-file calls.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Search text files under a scoped project path.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_search_code(array $input): array|WP_Error
{
    $query = (string) ($input['query'] ?? '');
    if ($query === '') {
        return new WP_Error('missing_query', 'query is required.');
    }

    $resolved = openmira_resolve_path(path: (string) ($input['path'] ?? 'wp-content'), must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_file($resolved) && !is_dir($resolved)) {
        return new WP_Error('not_searchable', sprintf('Path is not a file or directory: %s', $resolved));
    }

    $regex = openmira_search_boolean($input['regex'] ?? false);
    $case_sensitive = openmira_search_boolean($input['case_sensitive'] ?? false);
    $context_lines = max(0, min(10, (int) ($input['context_lines'] ?? 2)));
    $limit = max(1, min(500, (int) ($input['limit'] ?? 50)));
    $max_file_bytes = max(1024, min(10_485_760, (int) ($input['max_file_bytes'] ?? 1_048_576)));
    $include_hidden = openmira_search_boolean($input['include_hidden'] ?? false);
    $allow_broad_scan = openmira_search_boolean($input['allow_broad_scan'] ?? false);
    $max_candidate_files = max(1, min(5000, (int) ($input['max_candidate_files'] ?? 750)));
    $file_globs = openmira_search_file_globs($input['file_globs'] ?? null);
    $compiled_pattern = $regex ? openmira_search_compile_regex($query, $case_sensitive) : '';
    if (is_wp_error($compiled_pattern)) {
        return $compiled_pattern;
    }

    if (is_dir($resolved)) {
        $scope_error = openmira_search_validate_scope($resolved, $allow_broad_scan, $max_candidate_files);
        if (is_wp_error($scope_error)) {
            return $scope_error;
        }
    }

    $files = is_file($resolved)
        ? [$resolved]
        : openmira_search_collect_files($resolved, $file_globs, $include_hidden, $max_file_bytes, $max_candidate_files);
    if (is_wp_error($files)) {
        return $files;
    }

    $matches = [];
    $files_with_matches = [];
    $files_searched = 0;
    $files_skipped = 0;

    foreach ($files as $file) {
        if (!openmira_search_is_included_file($file, $file_globs, $include_hidden, $max_file_bytes)) {
            $files_skipped++;
            continue;
        }

        $content = file_get_contents($file);
        if ($content === false || !openmira_search_is_text($content)) {
            $files_skipped++;
            continue;
        }

        $files_searched++;
        $file_matches = openmira_search_file_matches(
            file: $file,
            content: $content,
            query: $query,
            regex: $regex,
            case_sensitive: $case_sensitive,
            compiled_pattern: $compiled_pattern,
            context_lines: $context_lines,
            remaining: $limit - count($matches),
        );

        if ($file_matches === []) {
            continue;
        }

        openmira_mark_file_read($file);
        $content_hash = hash_file(algo: 'sha256', filename: $file);
        $files_with_matches[openmira_display_path($file)] = [
            'path' => openmira_display_path($file),
            'content_hash' => is_string($content_hash) ? $content_hash : '',
            'read_tracked' => true,
        ];
        array_push($matches, ...$file_matches);
        if (count($matches) >= $limit) {
            break;
        }
    }

    return [
        'query' => $query,
        'path' => openmira_display_path($resolved),
        'regex' => $regex,
        'case_sensitive' => $case_sensitive,
        'file_globs' => $file_globs,
        'candidate_file_count' => count($files),
        'max_candidate_files' => $max_candidate_files,
        'matches' => $matches,
        'match_count' => count($matches),
        'files_with_matches' => array_values($files_with_matches),
        'files_searched' => $files_searched,
        'files_skipped' => $files_skipped,
        'truncated' => count($matches) >= $limit,
        'next_step_hints' => [
            'edit_file' => 'Matched files are read-tracked; use edit-file with enough exact context from a match.',
            'read_file' => 'Use read-file when you need the whole file before a larger edit.',
            'narrow_search' => 'For large scans, search a specific plugin/theme/sandbox directory or file path.',
        ],
    ];
}

/**
 * Parse booleans from REST GET strings and direct MCP booleans.
 */
function openmira_search_boolean(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (!is_string($value)) {
        return false;
    }

    return in_array(needle: strtolower($value), haystack: ['1', 'true', 'yes', 'on'], strict: true);
}

/**
 * Reject ambiguous roots unless the caller explicitly opts into a capped broad scan.
 */
function openmira_search_validate_scope(
    string $resolved,
    bool $allow_broad_scan,
    int $max_candidate_files,
): bool|WP_Error {
    if (!openmira_search_is_broad_scope($resolved)) {
        return true;
    }

    if ($allow_broad_scan) {
        return true;
    }

    return new WP_Error(
        'search_scope_too_broad',
        'Search path is too broad. Search a specific plugin, theme, sandbox directory, or file path.',
        [
            'path' => openmira_display_path($resolved),
            'allow_broad_scan' => false,
            'max_candidate_files' => $max_candidate_files,
            'suggested_paths' => openmira_search_suggested_paths(),
        ],
    );
}

/**
 * Decide whether a path is an ambiguous WordPress root.
 */
function openmira_search_is_broad_scope(string $resolved): bool
{
    $normalized = openmira_search_normalize_path($resolved);
    $broad_paths = [
        openmira_search_normalize_path(ABSPATH),
        openmira_search_normalize_path(WP_CONTENT_DIR),
        openmira_search_normalize_path(WP_PLUGIN_DIR),
        openmira_search_normalize_path(get_theme_root()),
    ];

    if (defined('WPMU_PLUGIN_DIR') && is_string(constant('WPMU_PLUGIN_DIR'))) {
        $broad_paths[] = openmira_search_normalize_path((string) constant('WPMU_PLUGIN_DIR'));
    }

    return in_array(needle: $normalized, haystack: array_values(array_unique($broad_paths)), strict: true);
}

/**
 * Normalize a filesystem path for scope comparison.
 */
function openmira_search_normalize_path(string $path): string
{
    $real = realpath($path);
    $normalized = is_string($real) ? $real : $path;

    return rtrim(string: str_replace(search: '\\', replace: '/', subject: $normalized), characters: '/');
}

/**
 * Return useful narrower search roots for error recovery.
 *
 * @return list<string>
 */
function openmira_search_suggested_paths(): array
{
    $paths = [];
    $active_theme = wp_get_theme();
    $stylesheet_directory = $active_theme->get_stylesheet_directory();
    if (is_dir($stylesheet_directory)) {
        $paths[] = openmira_display_path($stylesheet_directory);
    }

    $openmira_plugin = WP_PLUGIN_DIR . '/openmira';
    if (is_dir($openmira_plugin)) {
        $paths[] = openmira_display_path($openmira_plugin);
    }

    $sandbox = WP_CONTENT_DIR . '/openmira-sandbox';
    if (is_dir($sandbox)) {
        $paths[] = openmira_display_path($sandbox);
    }

    return array_values(array_unique($paths));
}

/**
 * Normalize include globs.
 *
 * @param mixed $value
 * @return list<string>
 */
function openmira_search_file_globs(mixed $value): array
{
    if (is_string($value) && $value !== '') {
        return [$value];
    }

    if (is_array($value)) {
        $globs = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($value as $glob) {
            if (!is_string($glob) || $glob === '') {
                continue;
            }

            $globs[] = $glob;
        }
        if ($globs !== []) {
            return array_values(array_unique($globs));
        }
    }

    return ['*.php', '*.js', '*.jsx', '*.ts', '*.tsx', '*.css', '*.scss', '*.json', '*.html'];
}

/**
 * Compile a regex query and validate it.
 *
 * @return string|WP_Error
 */
function openmira_search_compile_regex(string $query, bool $case_sensitive): string|WP_Error
{
    $delimiter = '~';
    $pattern =
        $delimiter . str_replace($delimiter, '\\' . $delimiter, $query) . $delimiter . ($case_sensitive ? 'u' : 'iu');
    set_error_handler(static fn(): bool => true);
    $ok = preg_match(pattern: $pattern, subject: '') !== false;
    restore_error_handler();

    if (!$ok) {
        return new WP_Error('invalid_regex', 'query is not a valid PCRE pattern.');
    }

    return $pattern;
}

/**
 * Collect candidate files under a directory.
 *
 * @param list<string> $file_globs
 * @return list<string>|WP_Error
 */
function openmira_search_collect_files(
    string $directory,
    array $file_globs,
    bool $include_hidden,
    int $max_file_bytes,
    int $max_candidate_files,
): array|WP_Error {
    if (!is_readable($directory)) {
        return new WP_Error('not_readable', sprintf('Directory is not readable: %s', $directory));
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        static function (mixed $item, string $_key, RecursiveDirectoryIterator $_iterator) use ($include_hidden): bool {
            if (!$item instanceof SplFileInfo) {
                return false;
            }

            return openmira_search_should_descend($item, $include_hidden);
        },
    ));

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        if (openmira_search_is_included_file($path, $file_globs, $include_hidden, $max_file_bytes)) {
            $files[] = $path;
            if (count($files) > $max_candidate_files) {
                return new WP_Error(
                    'too_many_candidate_files',
                    'Search would scan too many candidate files. Narrow the path or file_globs, or raise max_candidate_files intentionally.',
                    [
                        'path' => openmira_display_path($directory),
                        'candidate_file_limit' => $max_candidate_files,
                        'file_globs' => $file_globs,
                        'suggested_paths' => openmira_search_suggested_paths(),
                    ],
                );
            }
        }
    }

    sort($files);
    return $files;
}

/**
 * Decide whether to descend into an iterator item.
 */
function openmira_search_should_descend(SplFileInfo $item, bool $include_hidden): bool
{
    $name = $item->getFilename();
    if (!$include_hidden && str_starts_with($name, '.')) {
        return false;
    }

    if (!$item->isDir()) {
        return true;
    }

    return !in_array(
        needle: $name,
        haystack: [
            '.git',
            'node_modules',
            'vendor',
            'cache',
            'uploads',
            'upgrade',
            'openmira-file-backups',
        ],
        strict: true,
    );
}

/**
 * Decide whether a file should be searched.
 *
 * @param list<string> $file_globs
 */
function openmira_search_is_included_file(
    string $file,
    array $file_globs,
    bool $include_hidden,
    int $max_file_bytes,
): bool {
    $basename = basename($file);
    if (!$include_hidden && str_starts_with($basename, '.')) {
        return false;
    }

    $size = filesize($file);
    if (!is_readable($file) || $size === false || $size > $max_file_bytes) {
        return false;
    }

    foreach ($file_globs as $glob) {
        if (fnmatch($glob, $basename)) {
            return true;
        }
    }

    return false;
}

/**
 * Basic binary-content guard.
 */
function openmira_search_is_text(string $content): bool
{
    if (str_contains($content, "\0")) {
        return false;
    }

    return mb_check_encoding(value: $content, encoding: 'UTF-8');
}

/**
 * Find matches in one file.
 *
 * @return list<array<string, mixed>>
 */
// @mago-expect lint:excessive-parameter-list
function openmira_search_file_matches(
    string $file,
    string $content,
    string $query,
    bool $regex,
    bool $case_sensitive,
    string $compiled_pattern,
    int $context_lines,
    int $remaining,
): array {
    if ($remaining <= 0) {
        return [];
    }

    $lines = preg_split(pattern: "/\r\n|\n|\r/", subject: $content);
    if ($lines === false) {
        return [];
    }

    $matches = [];
    foreach ($lines as $index => $line) {
        $columns = $regex
            ? openmira_search_regex_columns($compiled_pattern, $line)
            : openmira_search_literal_columns($line, $query, $case_sensitive);

        foreach ($columns as $column) {
            $line_number = $index + 1;
            $matches[] = [
                'path' => openmira_display_path($file),
                'line' => $line_number,
                'column' => $column,
                'match' => $line,
                'context' => openmira_search_context($lines, $index, $context_lines),
            ];

            if (count($matches) >= $remaining) {
                return $matches;
            }
        }
    }

    return $matches;
}

/**
 * Return 1-based regex match columns for a line.
 *
 * @return list<int>
 */
function openmira_search_regex_columns(string $pattern, string $line): array
{
    $matched = preg_match(pattern: $pattern, subject: $line);
    if ($matched === false || $matched === 0) {
        return [];
    }

    return [1];
}

/**
 * Return 1-based literal match columns for a line.
 *
 * @return list<int>
 */
function openmira_search_literal_columns(string $line, string $query, bool $case_sensitive): array
{
    $columns = [];
    $offset = 0;
    while (true) {
        $position = $case_sensitive ? strpos($line, $query, $offset) : stripos($line, $query, $offset);
        if ($position === false) {
            break;
        }

        $columns[] = $position + 1;
        $offset = $position + max(1, strlen($query));
    }

    return $columns;
}

/**
 * Build line context around a match.
 *
 * @param list<string> $lines
 * @return list<array{line: int, text: string}>
 */
function openmira_search_context(array $lines, int $index, int $context_lines): array
{
    $start = max(0, $index - $context_lines);
    $end = min(count($lines) - 1, $index + $context_lines);
    $context = [];

    for ($current = $start; $current <= $end; $current++) {
        $context[] = [
            'line' => $current + 1,
            'text' => $lines[$current],
        ];
    }

    return $context;
}
