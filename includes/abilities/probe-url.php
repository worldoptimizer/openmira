<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Probe same-site URLs without browser cookies.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('openmira/probe-url', [
    'label' => __('Probe URL', domain: 'open-mira'),
    'description' => __(
        'Probes a same-site URL as an anonymous HTTP visitor and reports status, headers, redirects, and a bounded body excerpt.',
        domain: 'open-mira',
    ),
    'category' => 'wordpress-development',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'Same-site absolute or relative URL to probe.',
                'minLength' => 1,
            ],
            'method' => [
                'type' => 'string',
                'description' => 'HTTP method. Use GET when body_excerpt matters; HEAD for cheap status checks.',
                'enum' => ['GET', 'HEAD'],
                'default' => 'GET',
            ],
            'follow_redirects' => [
                'type' => 'boolean',
                'description' => 'Follow redirects instead of returning the first redirect response.',
                'default' => false,
            ],
            'max_redirects' => [
                'type' => 'integer',
                'description' => 'Maximum redirects when follow_redirects=true.',
                'default' => 3,
                'minimum' => 0,
                'maximum' => 10,
            ],
            'timeout_seconds' => [
                'type' => 'integer',
                'description' => 'HTTP timeout.',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 30,
            ],
            'body_excerpt_bytes' => [
                'type' => 'integer',
                'description' => 'Maximum response body bytes returned as body_excerpt.',
                'default' => 4096,
                'minimum' => 0,
                'maximum' => 65_536,
            ],
            'body_only' => [
                'type' => 'boolean',
                'description' => 'When true, body_excerpt starts at the opening <body> tag instead of the beginning of the HTML document.',
                'default' => false,
            ],
            'body_search' => [
                'type' => 'string',
                'description' => 'Optional case-insensitive string to search for in the response body. Returns a bounded match excerpt when found.',
                'default' => '',
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'openmira_probe_url',
    'permission_callback' => 'openmira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use for logged-out visitor verification, redirects, 404/private-content checks, and access-control behavior. Set body_only=true or body_search for shortcode/body checks on block themes with large inline CSS.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Probe a same-site URL as an anonymous HTTP visitor.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function openmira_probe_url(array $input): array|WP_Error
{
    $target_url = openmira_normalize_probe_url((string) ($input['url'] ?? ''));
    if (is_wp_error($target_url)) {
        return $target_url;
    }

    $method = strtoupper((string) ($input['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], strict: true)) {
        return new WP_Error('invalid_method', 'method must be GET or HEAD.');
    }

    $follow_redirects = ($input['follow_redirects'] ?? false) === true;
    $max_redirects = max(0, min(10, (int) ($input['max_redirects'] ?? 3)));
    $timeout = max(1, min(30, (int) ($input['timeout_seconds'] ?? 10)));
    $body_excerpt_bytes = max(0, min(65_536, (int) ($input['body_excerpt_bytes'] ?? 4096)));
    $body_only = ($input['body_only'] ?? false) === true;
    $body_search = trim((string) ($input['body_search'] ?? ''));
    $response_limit = $body_only || $body_search !== '' ? max($body_excerpt_bytes, 262_144) : $body_excerpt_bytes;

    $default_anonymous_cookie = 'playground_auto_login_already_happened=1';
    $anonymous_cookie = (string) apply_filters('openmira_probe_anonymous_cookie_header', $default_anonymous_cookie);

    $response = wp_remote_request($target_url, [
        'method' => $method,
        'timeout' => $timeout,
        'redirection' => $follow_redirects ? $max_redirects : 0,
        'limit_response_size' => $response_limit > 0 ? $response_limit : 1,
        'headers' => [
            'User-Agent' => 'OpenMiraURLProbe/' . (defined('OPENMIRA_VERSION') ? OPENMIRA_VERSION : 'dev'),
            'Cookie' => $anonymous_cookie,
        ],
        'cookies' => [],
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $headers = openmira_probe_headers($response);
    $location = openmira_probe_header_value($headers, name: 'location');
    $body = $method === 'HEAD' ? '' : wp_remote_retrieve_body($response);
    $excerpt_source = $body_only ? openmira_probe_body_only($body) : $body;

    return [
        'url' => $target_url,
        'method' => $method,
        'anonymous' => true,
        'follow_redirects' => $follow_redirects,
        'max_redirects' => $follow_redirects ? $max_redirects : 0,
        'status_code' => $status_code,
        'response_message' => wp_remote_retrieve_response_message($response),
        'is_redirect' => $status_code >= 300 && $status_code < 400,
        'redirect_location' => $location,
        'headers' => $headers,
        'body_excerpt' => $body_excerpt_bytes > 0
            ? substr($excerpt_source, offset: 0, length: $body_excerpt_bytes)
            : '',
        'body_excerpt_bytes' => $body_excerpt_bytes,
        'body_only' => $body_only,
        'body_search' => $body_search !== '' ? openmira_probe_body_search($body, $body_search) : null,
        'verification_hints' => [
            'redirect' => 'For anonymous redirect fixes, expect status_code 3xx and redirect_location when follow_redirects=false.',
            'private_posts' => 'WordPress serves private posts as 404 to anonymous visitors; redirect hooks may need to handle is_404() and parsed query vars, not only is_singular().',
            'body_excerpt' => 'For body-content checks on block themes, use body_only=true or body_search; inline CSS can appear before post content.',
            'screenshot' => 'Use screenshot-url for visual checks that require an authenticated browser; use probe-url for logged-out redirects and access control.',
        ],
    ];
}

/**
 * Return the HTML body portion when present.
 */
function openmira_probe_body_only(string $html): string
{
    $body_offset = stripos($html, needle: '<body');
    if ($body_offset === false) {
        return $html;
    }

    return substr($html, offset: $body_offset);
}

/**
 * Search the probed response body and return a bounded context excerpt.
 *
 * @return array{query: string, found: bool, offset: int, excerpt: string}
 */
function openmira_probe_body_search(string $body, string $query): array
{
    $offset = stripos($body, $query);
    if ($offset === false) {
        return [
            'query' => $query,
            'found' => false,
            'offset' => -1,
            'excerpt' => '',
        ];
    }

    $start = max(0, $offset - 500);

    return [
        'query' => $query,
        'found' => true,
        'offset' => $offset,
        'excerpt' => substr($body, offset: $start, length: 1500),
    ];
}

/**
 * Normalize a same-site probe URL.
 *
 * @return string|WP_Error
 */
function openmira_normalize_probe_url(string $url): string|WP_Error
{
    $url = trim($url);
    if ($url === '') {
        return new WP_Error('missing_url', 'Provide a URL to probe.');
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = home_url($url);
    }
    $url = esc_url_raw($url);
    if ($url === '') {
        return new WP_Error('invalid_url', 'Probe URL is invalid.');
    }

    // @mago-expect analysis:mixed-assignment
    $target_host = wp_parse_url($url, component: PHP_URL_HOST);
    // @mago-expect analysis:mixed-assignment
    $site_host = wp_parse_url(home_url('/'), component: PHP_URL_HOST);
    if (!is_string($target_host) || !is_string($site_host) || strcasecmp($target_host, $site_host) !== 0) {
        $allow_external = false;
        if (apply_filters('openmira_allow_external_probe_urls', $allow_external, $url) !== true) {
            return new WP_Error('external_url_not_allowed', 'Probe URLs must target the current WordPress site.');
        }
    }

    return $url;
}

/**
 * Return normalized response headers.
 *
 * @param array<string, mixed> $response
 * @return array<string, string>
 */
function openmira_probe_headers(array $response): array
{
    $raw_headers = wp_remote_retrieve_headers($response);
    $headers = [];

    if (is_array($raw_headers)) {
        // @mago-expect analysis:mixed-assignment
        foreach ($raw_headers as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $headers[strtolower((string) $name)] = (string) $value;
        }
        return $headers;
    }

    if (method_exists($raw_headers, 'getAll')) {
        // @mago-expect analysis:mixed-assignment
        foreach ($raw_headers->getAll() as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $headers[strtolower((string) $name)] = (string) $value;
        }
    }

    return $headers;
}

/**
 * Return one normalized response header.
 *
 * @param array<string, string> $headers
 */
function openmira_probe_header_value(array $headers, string $name): string
{
    $key = strtolower($name);
    return $headers[$key] ?? '';
}
