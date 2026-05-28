<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);
openmira_set_safety_mode('act', 30);

$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$remove_tree = static function (string $path) use (&$remove_tree): void {
    if ($path === '' || !file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $remove_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
};

$base = trailingslashit(WP_CONTENT_DIR) . 'openmira-path-security-base';
$evil = $base . '-evil';
$sandbox = openmira_get_sandbox_dir(ensure_exists: true);
$outside = trailingslashit(WP_CONTENT_DIR) . 'openmira-path-security-outside.txt';
$link = trailingslashit($sandbox) . 'openmira-path-security-link.txt';
$inside = trailingslashit($sandbox) . 'path-security/nested/ok.txt';

$cleanup = static function () use ($remove_tree, $base, $evil, $outside, $link, $inside): void {
    @unlink($link);
    @unlink($outside);
    @unlink($inside);
    $remove_tree(dirname(dirname($inside)));
    $remove_tree($evil);
    $remove_tree($base);
};

$cleanup();
wp_mkdir_p($base);
wp_mkdir_p($evil);

$base_filter = static fn(): string => $base;
add_filter('openmira_filesystem_base_dir', $base_filter);
$sibling = openmira_resolve_path($evil . '/x.txt', must_exist: false);
remove_filter('openmira_filesystem_base_dir', $base_filter);
if (!is_wp_error($sibling) || $sibling->get_error_code() !== 'path_outside_base') {
    $cleanup();
    $fail('Sibling-prefix path was not rejected as outside the base directory.');
}

$base_filter = static fn(): string => $base;
add_filter('openmira_filesystem_base_dir', $base_filter);
$boundary = openmira_resolve_path($base, must_exist: true);
remove_filter('openmira_filesystem_base_dir', $base_filter);
if (is_wp_error($boundary) || openmira_normalize_boundary_path((string) $boundary) !== openmira_normalize_boundary_path($base)) {
    $cleanup();
    $fail('Boundary directory itself was incorrectly rejected.');
}

file_put_contents($outside, 'outside-original');
if (!function_exists('symlink') || !symlink($outside, $link)) {
    $cleanup();
    $fail('Could not create symlink fixture for path security smoke.');
}

$symlink_write = openmira_write_file([
    'path' => openmira_display_path($link),
    'content' => 'outside-changed',
    'mode' => 'overwrite',
]);
if (!is_wp_error($symlink_write) || $symlink_write->get_error_code() !== 'symlink_write_rejected') {
    $cleanup();
    $fail('Write through final-path symlink was not rejected.');
}
if (file_get_contents($outside) !== 'outside-original') {
    $cleanup();
    $fail('Symlink write changed the outside target.');
}

$traversal = trailingslashit($sandbox) . 'a/b/../../../../etc/openmira-path-security.php';
$traversal_write = openmira_write_file([
    'path' => $traversal,
    'content' => "<?php\nreturn true;\n",
    'mode' => 'overwrite',
]);
if (!is_wp_error($traversal_write) || $traversal_write->get_error_code() !== 'php_sandbox_required') {
    $cleanup();
    $fail('Deep nonexistent traversal was not normalized and rejected by sandbox checks.');
}

$inside_write = openmira_write_file([
    'path' => openmira_display_path($inside),
    'content' => "inside-ok\n",
    'mode' => 'overwrite',
]);
if (is_wp_error($inside_write) || !is_file($inside) || file_get_contents($inside) !== "inside-ok\n") {
    $cleanup();
    $fail('Legitimate nested write inside the boundary failed.');
}

$cleanup();

echo wp_json_encode([
    'status' => 'ok',
    'checks' => [
        'sibling_prefix_rejected',
        'boundary_equals_allowed',
        'symlink_write_rejected',
        'deep_nonexistent_traversal_rejected',
        'legitimate_nested_write_allowed',
    ],
], JSON_PRETTY_PRINT) . "\n";
