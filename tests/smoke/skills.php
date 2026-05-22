<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

$smoke_skills_dir = WP_CONTENT_DIR . '/openmira-smoke-skills';
openmira_smoke_remove_dir($smoke_skills_dir);
add_filter('openmira_user_skills_dir', static fn(string $_dir): string => $smoke_skills_dir);
openmira_smoke_delete_cpt_skills();
delete_option(OPENMIRA_SKILLS_CPT_MIGRATION_OPTION);

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

if (!post_type_exists(OPENMIRA_SKILL_POST_TYPE)) {
    fwrite(STDERR, "Open Mira Skill CPT is not registered.\n");
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
    if (($skill['source'] ?? '') !== 'filesystem' || ($skill['enabled'] ?? false) !== true) {
        fwrite(STDERR, 'Built-in skill did not report filesystem source + enabled prompt: ' . wp_json_encode($skill) . PHP_EOL);
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
    ($skills_with_user['test-user-skill']['source'] ?? '') !== 'cpt'
    || ($skills_with_user['test-user-skill']['source_label'] ?? '') !== 'CPT'
    || !(openmira_get_cpt_skill_post('test-user-skill') instanceof WP_Post)
) {
    fwrite(STDERR, "CPT skill did not load with source=cpt.\n");
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
    ($skills_with_override['feedback']['source'] ?? '') !== 'cpt'
    || ($skills_with_override['feedback']['source_label'] ?? '') !== 'CPT (overrides built-in)'
    || ($skills_with_override['feedback']['overrides_built_in'] ?? false) !== true
) {
    fwrite(STDERR, "CPT override for built-in feedback skill did not win.\n");
    exit(1);
}

$disabled = openmira_upsert_cpt_skill([
    'id' => 'disabled-smoke-skill',
    'title' => 'Disabled Smoke Skill',
    'description' => 'Disabled prompt smoke.',
    'body' => '# Disabled Smoke Skill' . PHP_EOL . PHP_EOL . 'Body.',
    'enabled' => false,
]);
if (is_wp_error($disabled)) {
    fwrite(STDERR, $disabled->get_error_message() . PHP_EOL);
    exit(1);
}
openmira_register_skill_prompt_abilities();
if (wp_has_ability(openmira_skill_ability_name('disabled-smoke-skill'))) {
    fwrite(STDERR, "Disabled CPT skill registered a prompt ability.\n");
    exit(1);
}
$enabled = openmira_set_cpt_skill_prompt_enabled('disabled-smoke-skill', true);
if (is_wp_error($enabled)) {
    fwrite(STDERR, $enabled->get_error_message() . PHP_EOL);
    exit(1);
}
openmira_register_skill_prompt_abilities();
if (!wp_has_ability(openmira_skill_ability_name('disabled-smoke-skill'))) {
    fwrite(STDERR, "Enabled CPT skill did not register a prompt ability.\n");
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
$roundtrip_skill = openmira_get_skill('roundtrip-skill');
$exported = openmira_build_skill_markdown(
    (string) ($roundtrip_skill['title'] ?? ''),
    (string) ($roundtrip_skill['description'] ?? ''),
    (string) ($roundtrip_skill['body'] ?? ''),
);
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

$zip_path = openmira_create_user_skills_zip();
if (is_wp_error($zip_path)) {
    fwrite(STDERR, $zip_path->get_error_message() . PHP_EOL);
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($zip_path) !== true || $zip->getFromName('test-user-skill/SKILL.md') === false) {
    fwrite(STDERR, "CPT skill ZIP export did not include test-user-skill/SKILL.md.\n");
    exit(1);
}
$zip->close();
if (is_file($zip_path)) {
    unlink($zip_path);
}

$import_zip_path = wp_tempnam('openmira-skill-import.zip');
$import_zip = new ZipArchive();
if ($import_zip_path === '' || $import_zip->open($import_zip_path, ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create skill import ZIP.\n");
    exit(1);
}
$import_zip->addFromString(
    'zip-import-skill/SKILL.md',
    openmira_build_skill_markdown('ZIP Import Skill', 'ZIP import smoke.', "# ZIP Import Skill\n\nBody."),
);
$import_zip->close();
$zip_imported = openmira_import_skills_zip($import_zip_path, false);
if (is_file($import_zip_path)) {
    unlink($import_zip_path);
}
if (is_wp_error($zip_imported) || !(openmira_get_cpt_skill_post('zip-import-skill') instanceof WP_Post)) {
    fwrite(
        STDERR,
        is_wp_error($zip_imported) ? $zip_imported->get_error_message() . PHP_EOL : "ZIP import did not create CPT skill.\n",
    );
    exit(1);
}

openmira_smoke_remove_dir($smoke_skills_dir);
$legacy_dir = $smoke_skills_dir . '/legacy-skill';
wp_mkdir_p($legacy_dir);
file_put_contents(
    $legacy_dir . '/SKILL.md',
    openmira_build_skill_markdown('Legacy Skill', 'Legacy migration smoke.', "# Legacy Skill\n\nBody."),
);
delete_option(OPENMIRA_SKILLS_CPT_MIGRATION_OPTION);
$migration = openmira_migrate_legacy_user_skills_to_cpt();
if (is_wp_error($migration) || ($migration['migrated'] ?? 0) !== 1) {
    fwrite(
        STDERR,
        is_wp_error($migration) ? $migration->get_error_message() . PHP_EOL : 'Legacy filesystem migration did not migrate exactly one skill.' . PHP_EOL,
    );
    exit(1);
}
if (!(openmira_get_cpt_skill_post('legacy-skill') instanceof WP_Post)) {
    fwrite(STDERR, "Legacy filesystem skill did not migrate into CPT storage.\n");
    exit(1);
}

if (!did_action('admin_menu')) {
    do_action('admin_menu');
}
$expected_hook = 'open-mira_page_openmira-skills';
if (!has_action($expected_hook)) {
    fwrite(STDERR, "Skills page callback not registered against expected hook: {$expected_hook}\n");
    exit(1);
}

$original_get = $_GET;
$_GET = ['page' => 'openmira-skills', 'skill_action' => 'add'];
ob_start();
openmira_render_skills_page();
$add_page_html = ob_get_clean();
$_GET = $original_get;
if (!is_string($add_page_html) || !str_contains($add_page_html, 'pattern="^[a-z0-9][-a-z0-9._]{0,79}$"')) {
    fwrite(STDERR, "Skills add form did not render the fixed browser-safe pattern attribute.\n");
    exit(1);
}

$_GET = ['page' => 'openmira-skills', 'skill_action' => 'view', 'skill_id' => 'feedback'];
ob_start();
openmira_render_skills_page();
$view_page_html = ob_get_clean();
$_GET = $original_get;
if (!is_string($view_page_html) || !str_contains($view_page_html, 'openmira-admin-skill-preview')) {
    fwrite(STDERR, "Skills view page did not render the preview CSS class.\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'skills' => $ids,
    'prompts' => $expected_prompts,
    'legacy_user_skill_dir' => $smoke_skills_dir,
], JSON_PRETTY_PRINT) . PHP_EOL;

openmira_smoke_remove_dir($smoke_skills_dir);
openmira_smoke_delete_cpt_skills();
delete_option(OPENMIRA_SKILLS_CPT_MIGRATION_OPTION);

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
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

/**
 * Delete smoke-created CPT skill posts.
 */
function openmira_smoke_delete_cpt_skills(): void
{
    foreach (openmira_get_cpt_skill_posts() as $post) {
        wp_delete_post($post->ID, true);
    }
}
