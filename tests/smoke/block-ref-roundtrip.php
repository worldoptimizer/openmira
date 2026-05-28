<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

const OPENMIRA_REF_SMOKE_SLUG = 'openmira-ref-roundtrip-smoke';

/**
 * @param array<array-key, mixed> $blocks
 * @return array{refs: array<string, list<int>>, duplicates: array<string, list<list<int>>>}
 */
function openmira_ref_smoke_collect_refs(array $blocks, array $path = []): array
{
    $refs = [];
    $duplicates = [];

    foreach ($blocks as $index => $block) {
        if (!is_array($block)) {
            continue;
        }

        $current_path = array_merge($path, [(int) $index]);
        $ref = $block['attrs']['metadata']['_openmira_ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            if (isset($refs[$ref])) {
                if (!isset($duplicates[$ref])) {
                    $duplicates[$ref] = [$refs[$ref]];
                }
                $duplicates[$ref][] = $current_path;
            } else {
                $refs[$ref] = $current_path;
            }
        }

        $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $child_refs = openmira_ref_smoke_collect_refs($inner_blocks, $current_path);
        foreach ($child_refs['refs'] as $child_ref => $child_path) {
            if (isset($refs[$child_ref])) {
                if (!isset($duplicates[$child_ref])) {
                    $duplicates[$child_ref] = [$refs[$child_ref]];
                }
                $duplicates[$child_ref][] = $child_path;
            } else {
                $refs[$child_ref] = $child_path;
            }
        }
        foreach ($child_refs['duplicates'] as $duplicate_ref => $duplicate_paths) {
            if (!isset($duplicates[$duplicate_ref])) {
                $duplicates[$duplicate_ref] = [];
            }
            $duplicates[$duplicate_ref] = array_merge($duplicates[$duplicate_ref], $duplicate_paths);
        }
    }

    return ['refs' => $refs, 'duplicates' => $duplicates];
}

function openmira_ref_smoke_fail(string $message, mixed $context = null): never
{
    fwrite(STDERR, $message . PHP_EOL);
    if ($context !== null) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    exit(1);
}

foreach (
    get_posts([
        'name' => OPENMIRA_REF_SMOKE_SLUG,
        'post_type' => 'post',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
    ]) as $old_post_id
) {
    wp_delete_post((int) $old_post_id, force_delete: true);
}

$content = <<<'HTML'
<!-- wp:group {"metadata":{"_openmira_ref":"omr_smoke_group"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":2,"metadata":{"_openmira_ref":"omr_smoke_heading"}} -->
<h2 class="wp-block-heading">Open Mira ref smoke heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"metadata":{"_openmira_ref":"omr_smoke_paragraph"}} -->
<p>Open Mira ref smoke paragraph.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;

$parsed = parse_blocks($content);
$serialized = serialize_blocks($parsed);
$post_id = wp_insert_post([
    'post_title' => 'Open Mira Ref Roundtrip Smoke',
    'post_name' => OPENMIRA_REF_SMOKE_SLUG,
    'post_status' => 'publish',
    'post_type' => 'post',
    'post_content' => wp_slash($serialized),
], wp_error: true);

if (is_wp_error($post_id)) {
    openmira_ref_smoke_fail($post_id->get_error_message());
}

$saved = get_post((int) $post_id);
if (!$saved instanceof WP_Post) {
    openmira_ref_smoke_fail('Smoke post was not created.');
}

$roundtrip = openmira_ref_smoke_collect_refs(parse_blocks($saved->post_content));
$expected_refs = ['omr_smoke_group', 'omr_smoke_heading', 'omr_smoke_paragraph'];
foreach ($expected_refs as $expected_ref) {
    if (!isset($roundtrip['refs'][$expected_ref])) {
        openmira_ref_smoke_fail('Ref did not survive PHP parse/serialize/save/reparse.', [
            'missing_ref' => $expected_ref,
            'refs' => array_keys($roundtrip['refs']),
            'content' => $saved->post_content,
        ]);
    }
}

$duplicate_tree = parse_blocks($saved->post_content);
if (!isset($duplicate_tree[0]) || !is_array($duplicate_tree[0])) {
    openmira_ref_smoke_fail('Expected a top-level group block in smoke post.');
}

array_splice($duplicate_tree, 1, 0, [$duplicate_tree[0]]);
$duplicate_roundtrip = openmira_ref_smoke_collect_refs(parse_blocks(serialize_blocks($duplicate_tree)));
foreach ($expected_refs as $expected_ref) {
    if (!isset($duplicate_roundtrip['duplicates'][$expected_ref])) {
        openmira_ref_smoke_fail('Duplicate-ref policy cannot detect a duplicated block ref.', [
            'missing_duplicate_ref' => $expected_ref,
            'duplicates' => $duplicate_roundtrip['duplicates'],
        ]);
    }
}

$edit_url = get_edit_post_link((int) $post_id, context: 'raw');

echo wp_json_encode([
    'status' => 'ok',
    'post_id' => (int) $post_id,
    'edit_url' => is_string($edit_url) ? $edit_url : admin_url('post.php?post=' . (int) $post_id . '&action=edit'),
    'front_url' => get_permalink((int) $post_id),
    'refs' => $roundtrip['refs'],
    'duplicate_refs_detected' => array_keys($duplicate_roundtrip['duplicates']),
    'manual_gate' => 'Open the edit_url, make an unrelated editor edit, save, then verify metadata._openmira_ref still exists.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
