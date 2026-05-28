<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_read_blocks') || !function_exists('openmira_patch_blocks')) {
    fwrite(STDERR, "Open Mira block editing abilities are not loaded.\n");
    exit(1);
}

if (!WP_Block_Type_Registry::get_instance()->is_registered('openmira/smoke-dynamic')) {
    register_block_type('openmira/smoke-dynamic', [
        'attributes' => [
            'message' => ['type' => 'string'],
        ],
        'render_callback' => static function (array $attrs): string {
            return '<div class="openmira-smoke-dynamic">' . esc_html((string) ($attrs['message'] ?? '')) . '</div>';
        },
    ]);
}

openmira_set_safety_mode('act', 30);

$post_id = wp_insert_post([
    'post_title' => 'Open Mira Block Editing Smoke',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => <<<HTML
        <!-- wp:openmira/smoke-dynamic {"message":"One"} /-->
        <!-- wp:paragraph --><p>Static paragraph.</p><!-- /wp:paragraph -->
        HTML,
], wp_error: true);

if (is_wp_error($post_id)) {
    fwrite(STDERR, $post_id->get_error_message() . "\n");
    exit(1);
}

$fail = static function (string $message) use ($post_id): void {
    wp_delete_post($post_id, force_delete: true);
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$before_read_content = get_post_field('post_content', $post_id);
if (!is_string($before_read_content)) {
    $fail('Could not read initial post_content.');
}
$read = openmira_read_blocks([
    'post_id' => $post_id,
    'include_attrs' => true,
    'max_depth' => 3,
]);
if (is_wp_error($read)) {
    $fail($read->get_error_message());
}

$after_read_content = get_post_field('post_content', $post_id);
if (!is_string($after_read_content) || $after_read_content !== $before_read_content) {
    $fail('read-blocks mutated post_content.');
}
$read_blocks = is_array($read['blocks'] ?? null) ? $read['blocks'] : [];
if (($read['block_count'] ?? 0) !== 2 || count($read_blocks) !== 2) {
    $fail('read-blocks did not return the expected two-block tree.');
}

$dynamic_ref = (string) ($read['blocks'][0]['ref'] ?? '');
if (!str_starts_with($dynamic_ref, 'v:')) {
    $fail('Initial dynamic block did not receive a virtual ref.');
}

$updated = openmira_patch_blocks([
    'post_id' => $post_id,
    'expected_etag' => (string) $read['etag'],
    'create_backup' => false,
    'operations' => [
        [
            'operation' => 'update',
            'ref' => $dynamic_ref,
            'attrs' => ['message' => 'Two'],
        ],
    ],
]);
if (is_wp_error($updated)) {
    $fail($updated->get_error_message());
}
if (($updated['operations_applied'] ?? 0) !== 1) {
    $fail('patch-blocks did not report one applied operation.');
}

$updated_content = get_post_field('post_content', $post_id);
if (!is_string($updated_content)) {
    $fail('Could not read updated post_content.');
}
if (!str_contains($updated_content, '"message":"Two"') || !str_contains($updated_content, '_openmira_ref')) {
    $fail('patch-blocks did not update attrs and persist block refs.');
}

$conflict = openmira_patch_blocks([
    'post_id' => $post_id,
    'expected_etag' => (string) $read['etag'],
    'create_backup' => false,
    'operations' => [
        [
            'operation' => 'update',
            'ref' => (string) ($updated['blocks'][0]['ref'] ?? ''),
            'attrs' => ['message' => 'Conflict'],
        ],
    ],
]);
if (!is_wp_error($conflict) || $conflict->get_error_code() !== 'block_etag_conflict') {
    $fail('patch-blocks did not reject a stale expected_etag.');
}

$second_read = openmira_read_blocks(['post_id' => $post_id, 'include_attrs' => true]);
if (is_wp_error($second_read)) {
    $fail($second_read->get_error_message());
}

$persisted_dynamic_ref = (string) ($second_read['blocks'][0]['ref'] ?? '');
$paragraph_ref = (string) ($second_read['blocks'][1]['ref'] ?? '');
if (str_starts_with($persisted_dynamic_ref, 'v:') || str_starts_with($paragraph_ref, 'v:')) {
    $fail('Durable refs were not persisted on write.');
}

$abort_content = get_post_field('post_content', $post_id);
if (!is_string($abort_content)) {
    $fail('Could not read abort baseline post_content.');
}
$abort_hash = openmira_hash_content($abort_content);
$abort = openmira_patch_blocks([
    'post_id' => $post_id,
    'expected_etag' => (string) $second_read['etag'],
    'create_backup' => false,
    'operations' => [
        [
            'operation' => 'update',
            'ref' => $persisted_dynamic_ref,
            'attrs' => ['message' => 'Should not save'],
        ],
        [
            'operation' => 'delete',
            'ref' => 'omr_missing_ref',
        ],
    ],
]);
$after_abort_content = get_post_field('post_content', $post_id);
if (
    !is_wp_error($abort)
    || !is_string($after_abort_content)
    || openmira_hash_content($after_abort_content) !== $abort_hash
) {
    $fail('patch-blocks did not abort atomically before saving.');
}

$static_delete = openmira_patch_blocks([
    'post_id' => $post_id,
    'expected_etag' => (string) $second_read['etag'],
    'create_backup' => false,
    'operations' => [
        [
            'operation' => 'delete',
            'ref' => $paragraph_ref,
        ],
    ],
]);
if (!is_wp_error($static_delete) || $static_delete->get_error_code() !== 'block_runtime_required') {
    $fail('patch-blocks did not route static block edits to the runtime-required error.');
}

$inserted = openmira_patch_blocks([
    'post_id' => $post_id,
    'expected_etag' => (string) $second_read['etag'],
    'create_backup' => false,
    'operations' => [
        [
            'operation' => 'insert',
            'after' => $persisted_dynamic_ref,
            'block_markup' => '<!-- wp:openmira/smoke-dynamic {"message":"Inserted"} /-->',
        ],
    ],
]);
if (is_wp_error($inserted)) {
    $fail($inserted->get_error_message());
}
$inserted_content = get_post_field('post_content', $post_id);
if (
    ($inserted['block_count'] ?? 0) !== 3
    || !is_string($inserted_content)
    || !str_contains($inserted_content, 'Inserted')
) {
    $fail('patch-blocks did not insert a dynamic block.');
}

$grammar_read = openmira_read_blocks(['post_id' => $post_id, 'include_attrs' => true]);
if (is_wp_error($grammar_read)) {
    $fail($grammar_read->get_error_message());
}
$grammar_ref = (string) ($grammar_read['blocks'][0]['ref'] ?? '');
$grammar_patch = <<<PATCH
    *** Begin Patch
    *** Update Block (ref: {$grammar_ref}):
    {"attrs":{"message":"Grammar"}}
    *** End Patch
    PATCH;
$grammar_result = openmira_apply_patch([
    'post_id' => $post_id,
    'expected_etag' => (string) ($grammar_read['etag'] ?? ''),
    'patch' => $grammar_patch,
]);
if (is_wp_error($grammar_result)) {
    $fail($grammar_result->get_error_message());
}
$grammar_content = get_post_field('post_content', $post_id);
if (!is_string($grammar_content) || !str_contains($grammar_content, 'Grammar')) {
    $fail('apply-patch block hunk did not route through patch-blocks.');
}

$updated_ref_changes = is_array($updated['ref_changes'] ?? null) ? $updated['ref_changes'] : [];
$updated_assigned_refs = is_array($updated_ref_changes['assigned'] ?? null) ? $updated_ref_changes['assigned'] : [];

wp_delete_post($post_id, force_delete: true);

$output = wp_json_encode([
    'status' => 'ok',
    'updated_refs' => count($updated_assigned_refs),
    'inserted_block_count' => $inserted['block_count'] ?? 0,
], JSON_PRETTY_PRINT);

echo (is_string($output) ? $output : '{"status":"ok"}') . "\n";
