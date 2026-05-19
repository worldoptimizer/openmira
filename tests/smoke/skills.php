<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

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

echo wp_json_encode([
    'status' => 'ok',
    'skills' => $ids,
    'prompts' => $expected_prompts,
], JSON_PRETTY_PRINT) . PHP_EOL;
