<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_apply_patch')) {
    fwrite(STDERR, "Open Mira apply-patch ability is not loaded.\n");
    exit(1);
}

openmira_set_safety_mode('act', 30);

$theme = openmira_scaffold_theme([
    'type' => 'block',
    'slug' => 'openmira-patch-smoke',
    'name' => 'Open Mira Patch Smoke',
    'description' => 'Smoke test theme for Open Mira patch grammar.',
    'design_brief' => 'Minimal block theme for theme.json patch validation.',
    'activate' => true,
    'overwrite' => true,
    'force_clean' => true,
]);

if (is_wp_error($theme)) {
    fwrite(STDERR, $theme->get_error_message() . "\n");
    exit(1);
}

$theme_json_path = 'wp-content/themes/openmira-patch-smoke/theme.json';
$read = openmira_read_file(['path' => $theme_json_path, 'limit' => -1]);
if (is_wp_error($read)) {
    fwrite(STDERR, $read->get_error_message() . "\n");
    exit(1);
}

$dry_run = openmira_apply_patch([
    'dry_run' => true,
    'patch' => <<<PATCH
        *** Begin Patch
        *** Update theme.json (path: settings.color.palette[primary].color):
        "#ff5500"
        *** End Patch
        PATCH,
]);

if (is_wp_error($dry_run)) {
    fwrite(STDERR, $dry_run->get_error_message() . "\n");
    exit(1);
}

if (($dry_run['dry_run'] ?? false) !== true || ($dry_run['diff'] ?? '') === '') {
    fwrite(STDERR, "Dry run did not return a diff.\n");
    exit(1);
}

$result = openmira_apply_patch([
    'patch' => <<<PATCH
        *** Begin Patch
        *** Update theme.json (path: settings.color.palette[primary].color):
        "#ff5500"
        *** Update theme.json (path: styles.elements.button, mode: merge):
        {
          "color": {
            "background": "var:preset|color|primary",
            "text": "var:preset|color|base"
          }
        }
        *** End Patch
        PATCH,
]);

if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}

$bulk_result = openmira_apply_patch([
    'expected_current_hash' => (string) ($result['content_hash'] ?? ''),
    'include_diff' => false,
    'patch' => <<<PATCH
        *** Begin Patch
        *** Update theme.json (paths, mode: merge):
        {
          "settings.typography.fontSizes": [
            { "slug": "display", "size": "clamp(3rem, 8vw, 7rem)", "name": "Display" }
          ],
          "styles.elements.link": {
            "color": {
              "text": "var:preset|color|primary"
            }
          }
        }
        *** End Patch
        PATCH,
]);

if (is_wp_error($bulk_result)) {
    fwrite(STDERR, $bulk_result->get_error_message() . "\n");
    exit(1);
}

if (array_key_exists('diff', $bulk_result) || ($bulk_result['diff_summary'] ?? '') === '') {
    fwrite(STDERR, "Bulk patch diff suppression did not return the expected shape.\n");
    exit(1);
}

$content = file_get_contents(get_theme_root() . '/openmira-patch-smoke/theme.json');
if (!is_string($content)) {
    fwrite(STDERR, "Could not read patched theme.json.\n");
    exit(1);
}

$json = json_decode($content, true);
if (!is_array($json)) {
    fwrite(STDERR, "Patched theme.json is not valid JSON.\n");
    exit(1);
}

$primary = null;
foreach (($json['settings']['color']['palette'] ?? []) as $entry) {
    if (is_array($entry) && ($entry['slug'] ?? '') === 'primary') {
        $primary = $entry;
        break;
    }
}

if (!is_array($primary) || ($primary['color'] ?? '') !== '#ff5500') {
    fwrite(STDERR, "Primary palette color was not patched.\n");
    exit(1);
}

if (($json['styles']['elements']['button']['color']['background'] ?? '') !== 'var:preset|color|primary') {
    fwrite(STDERR, "Button style merge did not apply.\n");
    exit(1);
}

if (($json['settings']['typography']['fontSizes'][0]['slug'] ?? '') !== 'display') {
    fwrite(STDERR, "Bulk fontSizes path did not apply.\n");
    exit(1);
}

if (($json['styles']['elements']['link']['color']['text'] ?? '') !== 'var:preset|color|primary') {
    fwrite(STDERR, "Bulk link style path did not apply.\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'operations' => count($result['operations'] ?? []) + count($bulk_result['operations'][0]['operations'] ?? []),
    'theme' => $result['theme']['slug'] ?? '',
], JSON_PRETTY_PRINT) . "\n";
