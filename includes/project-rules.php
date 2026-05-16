<?php

declare(strict_types=1);

/**
 * Project rules discovery and memory overlay.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_RULES_PATH = '.openmirarules.json';

/**
 * Read project rules from .openmirarules plus durable memory overlay.
 *
 * @return array<string, mixed>
 */
function openmira_get_project_rules(): array
{
    $rules = openmira_read_project_rules_file();
    $memory_overlay = openmira_project_rules_memory_overlay();
    $merged = openmira_merge_project_rules(is_array($rules['parsed'] ?? null) ? $rules['parsed'] : [], $memory_overlay);

    $found = ($rules['found'] ?? false) === true;

    return [
        'found' => $found,
        'path' => (string) ($rules['path'] ?? ''),
        'format' => (string) ($rules['format'] ?? ''),
        'parsed' => $merged,
        'raw' => (string) ($rules['raw'] ?? ''),
        'memory_overlay' => $memory_overlay,
        'candidates' => openmira_project_rules_candidates(),
    ];
}

/**
 * Read rules file.
 *
 * @return array<string, mixed>
 */
function openmira_read_project_rules_file(): array
{
    foreach (openmira_project_rules_candidates() as $candidate) {
        $path = (string) ($candidate['path'] ?? '');
        if ($path === '' || !is_readable($path)) {
            continue;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            continue;
        }

        $parsed = openmira_parse_project_rules($contents, $path);

        return [
            'found' => true,
            'path' => openmira_display_path($path),
            'format' => str_ends_with($path, '.json') ? 'json' : 'text',
            'parsed' => $parsed,
            'raw' => $contents,
        ];
    }

    return [
        'found' => false,
        'path' => '',
        'format' => '',
        'parsed' => [],
        'raw' => '',
    ];
}

/**
 * Return likely project rules paths.
 *
 * @return list<array<string, mixed>>
 */
function openmira_project_rules_candidates(): array
{
    $paths = [
        ABSPATH . '.openmirarules.json',
        ABSPATH . '.openmirarules',
        WP_CONTENT_DIR . '/.openmirarules.json',
        WP_CONTENT_DIR . '/.openmirarules',
        get_stylesheet_directory() . '/.openmirarules.json',
        get_stylesheet_directory() . '/.openmirarules',
    ];

    $items = [];
    foreach (array_values(array_unique($paths)) as $path) {
        $items[] = [
            'path' => $path,
            'display_path' => openmira_display_path($path),
            'exists' => file_exists($path),
            'readable' => is_readable($path),
            'writable' => file_exists($path) ? wp_is_writable($path) : wp_is_writable(dirname($path)),
        ];
    }

    return $items;
}

/**
 * Parse JSON or simple key/value rules.
 *
 * @return array<string, mixed>
 */
function openmira_parse_project_rules(string $contents, string $path): array
{
    if (str_ends_with($path, '.json')) {
        // @mago-expect analysis:mixed-assignment
        $decoded = json_decode($contents, associative: true);
        return is_array($decoded) ? openmira_normalize_rules_array($decoded) : [];
    }

    return openmira_parse_project_rules_text($contents);
}

/**
 * Parse simple YAML-like key/value rules.
 *
 * @return array<string, mixed>
 */
function openmira_parse_project_rules_text(string $contents): array
{
    $rules = [];
    $lines = preg_split(pattern: '/\\R/', subject: $contents);
    foreach (is_array($lines) ? $lines : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode(separator: ':', string: $line, limit: 2));
        if ($key === '') {
            continue;
        }
        $rules[$key] = trim(string: $value, characters: "\"'");
    }

    return $rules;
}

/**
 * Return project-rules overlay from memory entries.
 *
 * @return array<string, mixed>
 */
function openmira_project_rules_memory_overlay(): array
{
    if (!function_exists('openmira_get_memory_entries')) {
        return [];
    }

    $overlay = [];
    foreach (openmira_get_memory_entries() as $key => $entry) {
        if (!str_starts_with($key, 'rules.')) {
            continue;
        }
        $rules_key = substr($key, strlen('rules.'));
        $value = is_string($entry['value'] ?? null) ? $entry['value'] : '';
        if ($rules_key !== '' && $value !== '') {
            $overlay[$rules_key] = $value;
        }
    }

    return $overlay;
}

/**
 * Merge rule arrays.
 *
 * @param array<array-key, mixed> $rules
 * @param array<array-key, mixed> $overlay
 * @return array<string, mixed>
 */
function openmira_merge_project_rules(array $rules, array $overlay): array
{
    return array_merge(openmira_normalize_rules_array($rules), openmira_normalize_rules_array($overlay));
}

/**
 * Normalize rule array keys.
 *
 * @param array<array-key, mixed> $rules
 * @return array<string, mixed>
 */
function openmira_normalize_rules_array(array $rules): array
{
    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($rules as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * Return one project rule as a string.
 */
function openmira_project_rule_string(string $key, string $default = ''): string
{
    $rules = openmira_get_project_rules();
    $parsed = is_array($rules['parsed'] ?? null) ? $rules['parsed'] : [];
    // @mago-expect analysis:mixed-assignment
    $value = $parsed[$key] ?? null;

    return is_scalar($value) ? (string) $value : $default;
}

/**
 * Build default rules payload for a project.
 *
 * @return array<string, mixed>
 */
function openmira_default_project_rules(): array
{
    $stylesheet = get_stylesheet();

    return [
        'php_target' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'wp_target' => get_bloginfo('version'),
        'text_domain' => $stylesheet !== '' ? $stylesheet : 'open-mira',
        'namespace_prefix' => 'OpenMira',
        'function_prefix' => 'openmira',
        'preferred_theme_type' => function_exists('wp_is_block_theme') && wp_is_block_theme() ? 'block' : 'classic',
        'preferred_block_type' => 'dynamic',
        'coding_standard' => 'WordPress',
    ];
}
