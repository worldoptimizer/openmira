<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

$smoke_skills_dir = WP_CONTENT_DIR . '/openmira-smoke-skills';
openmira_smoke_remove_dir($smoke_skills_dir);
add_filter('openmira_user_skills_dir', static fn(string $_dir): string => $smoke_skills_dir);

$expected = ['build-a-block-theme', 'feedback', 'wp-aware-editing'];

foreach ($expected as $skill_id) {
    $path = WP_PLUGIN_DIR . '/openmira/includes/skills/' . $skill_id . '/SKILL.md';
    if (!is_file($path)) {
        fwrite(STDERR, "Missing skill file: {$path}\n");
        exit(1);
    }
}

if (!function_exists('openmira_list_skills_ability') || !function_exists('openmira_get_skill_ability')) {
    fwrite(STDERR, "Open Mira skill abilities are not loaded.\n");
    exit(1);
}

$list = openmira_list_skills_ability();
$skills = $list['skills'] ?? [];
if (!is_array($skills) || count($skills) !== 3) {
    fwrite(STDERR, "Expected exactly three installed skills.\n");
    exit(1);
}

$ids = array_map(static fn(array $skill): string => (string) ($skill['id'] ?? ''), $skills);
sort($ids);
if ($ids !== $expected) {
    fwrite(STDERR, 'Unexpected skill IDs: ' . wp_json_encode($ids) . PHP_EOL);
    exit(1);
}

foreach ($skills as $skill) {
    if (($skill['title'] ?? '') === '' || ($skill['description'] ?? '') === '') {
        fwrite(STDERR, 'Skill has empty title or description: ' . wp_json_encode($skill) . PHP_EOL);
        exit(1);
    }
}

$feedback = openmira_get_skill_ability(['skill_id' => 'feedback']);
if (is_wp_error($feedback)) {
    fwrite(STDERR, $feedback->get_error_message() . PHP_EOL);
    exit(1);
}
if (strlen((string) ($feedback['body'] ?? '')) <= 500) {
    fwrite(STDERR, "feedback body is unexpectedly short.\n");
    exit(1);
}

if (!class_exists('WP\\MCP\\Core\\McpAdapter')) {
    fwrite(STDERR, "MCP Adapter class is not loaded.\n");
    exit(1);
}

$adapter = \WP\MCP\Core\McpAdapter::instance();
$server = $adapter->get_server('openmira');
if ($server === null) {
    fwrite(STDERR, "Open Mira MCP server is not registered.\n");
    exit(1);
}

$prompts = $server->get_prompts();
$prompt_names = array_keys($prompts);
sort($prompt_names);
$expected_prompts = [
    'openmira.build-a-block-theme',
    'openmira.feedback',
    'openmira.wp-aware-editing',
];
foreach ($expected_prompts as $prompt_name) {
    if (!in_array($prompt_name, $prompt_names, true)) {
        fwrite(STDERR, "Missing MCP prompt: {$prompt_name}\n");
        fwrite(STDERR, 'Registered prompts: ' . wp_json_encode($prompt_names) . PHP_EOL);
        exit(1);
    }
}

$prompt = $server->get_mcp_prompt('openmira.feedback');
if ($prompt === null) {
    fwrite(STDERR, "Could not fetch openmira.feedback prompt.\n");
    exit(1);
}

$permission = $prompt->check_permission([]);
if ($permission !== true) {
    fwrite(STDERR, "Prompt permission check failed.\n");
    exit(1);
}

$result = $prompt->execute([]);
if (!is_array($result) || !isset($result['messages'][0]['content']['text'])) {
    fwrite(STDERR, "Prompt execution did not return a text message.\n");
    exit(1);
}
if (strlen((string) $result['messages'][0]['content']['text']) <= 500) {
    fwrite(STDERR, "Prompt execution returned unexpectedly short content.\n");
    exit(1);
}

if (!function_exists('openmira_save_user_skill') || !function_exists('openmira_import_skill_markdown')) {
    fwrite(STDERR, "Open Mira skill admin actions are not loaded.\n");
    exit(1);
}

$save = openmira_save_user_skill([
    'id' => 'test-user-skill',
    'title' => 'Test User Skill',
    'description' => 'Smoke user skill.',
    'body' => '# Test User Skill' . PHP_EOL . PHP_EOL . 'Body.',
]);
if (is_wp_error($save)) {
    fwrite(STDERR, $save->get_error_message() . PHP_EOL);
    exit(1);
}
$skills_with_user = openmira_get_skills();
if (
    ($skills_with_user['test-user-skill']['source'] ?? '') !== 'user'
    || ($skills_with_user['test-user-skill']['source_label'] ?? '') !== 'user'
) {
    fwrite(STDERR, "User-content skill did not load with source=user.\n");
    exit(1);
}

$override = openmira_save_user_skill([
    'id' => 'feedback',
    'title' => 'Custom Feedback',
    'description' => 'Override smoke.',
    'body' => '# Custom Feedback' . PHP_EOL . PHP_EOL . 'Override body.',
]);
if (is_wp_error($override)) {
    fwrite(STDERR, $override->get_error_message() . PHP_EOL);
    exit(1);
}
$skills_with_override = openmira_get_skills();
if (
    ($skills_with_override['feedback']['source'] ?? '') !== 'user'
    || ($skills_with_override['feedback']['source_label'] ?? '') !== 'user-override'
    || ($skills_with_override['feedback']['overrides_built_in'] ?? false) !== true
) {
    fwrite(STDERR, "User override for built-in feedback skill did not win.\n");
    exit(1);
}

wp_set_current_user(0);
$denied_save = openmira_handle_skill_save_action([
    'skill_id' => 'denied-skill',
    'skill_title' => 'Denied',
    'skill_description' => 'Denied',
    'skill_body' => 'Denied',
]);
wp_set_current_user(1);
if (!is_wp_error($denied_save) || $denied_save->get_error_code() !== 'openmira_skill_permission_denied') {
    fwrite(STDERR, "Skill save handler did not deny non-admin user.\n");
    exit(1);
}

$invalid_save = openmira_handle_skill_save_action([
    'skill_id' => 'Bad Skill!',
    'skill_title' => 'Bad',
    'skill_description' => 'Bad',
    'skill_body' => 'Bad',
]);
if (!is_wp_error($invalid_save) || $invalid_save->get_error_code() !== 'openmira_invalid_skill_id') {
    fwrite(STDERR, "Malformed skill ID was not rejected.\n");
    exit(1);
}

$roundtrip = openmira_save_user_skill([
    'id' => 'roundtrip-skill',
    'title' => 'Roundtrip Skill',
    'description' => 'Roundtrip smoke.',
    'body' => '# Roundtrip Skill' . PHP_EOL . PHP_EOL . 'Original body.',
]);
if (is_wp_error($roundtrip)) {
    fwrite(STDERR, $roundtrip->get_error_message() . PHP_EOL);
    exit(1);
}
$roundtrip_file = openmira_user_skill_file_path('roundtrip-skill');
$exported = file_get_contents($roundtrip_file);
if (!is_string($exported)) {
    fwrite(STDERR, "Could not read exported skill file.\n");
    exit(1);
}
$deleted = openmira_delete_user_skill('roundtrip-skill');
if (is_wp_error($deleted)) {
    fwrite(STDERR, $deleted->get_error_message() . PHP_EOL);
    exit(1);
}
$imported = openmira_import_skill_markdown('roundtrip-skill', $exported, false);
if (is_wp_error($imported)) {
    fwrite(STDERR, $imported->get_error_message() . PHP_EOL);
    exit(1);
}
$roundtrip_skill = openmira_get_skill('roundtrip-skill');
if (($roundtrip_skill['body'] ?? '') !== "# Roundtrip Skill\n\nOriginal body.\n") {
    fwrite(STDERR, "Skill import roundtrip did not preserve the body.\n");
    exit(1);
}

global $admin_page_hooks;
if (!did_action('admin_menu')) {
    do_action('admin_menu');
}
if (!isset($admin_page_hooks['openmira-connect'])) {
    fwrite(STDERR, "Parent menu openmira-connect not registered\n");
    exit(1);
}
$expected_hook = $admin_page_hooks['openmira-connect'] . '_page_openmira-skills';
if (!has_action($expected_hook)) {
    fwrite(STDERR, "Skills page callback not registered against expected hook: {$expected_hook}\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'skills' => $ids,
    'prompts' => $expected_prompts,
    'user_skill_dir' => $smoke_skills_dir,
], JSON_PRETTY_PRINT) . PHP_EOL;

openmira_smoke_remove_dir($smoke_skills_dir);

/**
 * Remove a smoke directory recursively.
 */
function openmira_smoke_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items === false ? [] : $items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            openmira_smoke_remove_dir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
