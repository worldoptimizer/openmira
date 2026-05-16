<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_scaffold_theme')) {
    fwrite(STDERR, "Open Mira is not loaded.\n");
    exit(1);
}

openmira_set_safety_mode('act', 30);

$theme = openmira_scaffold_theme([
    'type' => 'block',
    'slug' => 'openmira-wp-env-smoke',
    'name' => 'Open Mira WP Env Smoke',
    'description' => 'Smoke test theme scaffolded by Open Mira.',
    'design_brief' => 'Minimal block theme created by the wp-env smoke test.',
    'activate' => true,
    'overwrite' => true,
]);

if (is_wp_error($theme)) {
    fwrite(STDERR, $theme->get_error_message() . "\n");
    exit(1);
}

$theme_dir = get_theme_root() . '/openmira-wp-env-smoke';
$required_files = [
    'style.css',
    'theme.json',
    'functions.php',
    'templates/index.html',
    'templates/page.html',
    'parts/header.html',
    'parts/footer.html',
    'patterns/hero.php',
];

foreach ($required_files as $relative_path) {
    if (!is_file($theme_dir . '/' . $relative_path)) {
        fwrite(STDERR, "Missing scaffolded file: {$relative_path}\n");
        exit(1);
    }
}

if (get_stylesheet() !== 'openmira-wp-env-smoke') {
    fwrite(STDERR, "Smoke theme was not activated.\n");
    exit(1);
}

$front_page = wp_remote_get(home_url('/'));
if (is_wp_error($front_page)) {
    fwrite(STDERR, $front_page->get_error_message() . "\n");
    exit(1);
}

$body = (string) wp_remote_retrieve_body($front_page);
if (!str_contains($body, 'Open Mira Theme Build')) {
    fwrite(STDERR, "Front-end render did not include scaffolded theme marker.\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'theme' => $theme['theme']['slug'],
    'files' => count($theme['files']),
], JSON_PRETTY_PRINT) . "\n";
