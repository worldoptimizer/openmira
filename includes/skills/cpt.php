<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * CPT-backed user Skills.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_SKILL_POST_TYPE = 'openmira_skill';

const OPENMIRA_SKILL_ID_META = '_openmira_skill_id';

const OPENMIRA_SKILL_ENABLE_PROMPT_META = '_openmira_enable_prompt';

const OPENMIRA_SKILLS_CPT_MIGRATION_OPTION = 'openmira_skills_cpt_migrated_160';

/**
 * Register the private user Skill CPT.
 */
function openmira_register_skill_cpt(): void
{
    register_post_type(OPENMIRA_SKILL_POST_TYPE, [
        'labels' => [
            'name' => __('Open Mira Skills', domain: 'open-mira'),
            'singular_name' => __('Open Mira Skill', domain: 'open-mira'),
        ],
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'],
        'capability_type' => 'openmira_skill',
        'map_meta_cap' => false,
        'capabilities' => [
            'edit_post' => 'manage_options',
            'read_post' => 'manage_options',
            'delete_post' => 'manage_options',
            'edit_posts' => 'manage_options',
            'edit_others_posts' => 'manage_options',
            'delete_posts' => 'manage_options',
            'publish_posts' => 'manage_options',
            'read_private_posts' => 'manage_options',
            'create_posts' => 'manage_options',
        ],
    ]);

    register_post_meta(OPENMIRA_SKILL_POST_TYPE, OPENMIRA_SKILL_ID_META, [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => false,
        'auth_callback' => static fn(): bool => current_user_can('manage_options'),
    ]);

    register_post_meta(OPENMIRA_SKILL_POST_TYPE, OPENMIRA_SKILL_ENABLE_PROMPT_META, [
        'type' => 'boolean',
        'single' => true,
        'default' => true,
        'show_in_rest' => false,
        'auth_callback' => static fn(): bool => current_user_can('manage_options'),
    ]);
}

add_action('init', callback: 'openmira_register_skill_cpt');

/**
 * Keep a bounded native revision history for user Skills.
 */
add_filter(
    'wp_revisions_to_keep',
    static function (int $num, WP_Post $post): int {
        return $post->post_type === OPENMIRA_SKILL_POST_TYPE ? 10 : $num;
    },
    priority: 10,
    accepted_args: 2,
);

/**
 * CPT-backed user skill source.
 */
final class OpenMira_CPT_Skill_Source implements OpenMira_Skill_Source
{
    public function list_skills(): array
    {
        /** @var array<string, array<string, mixed>> $skills */
        $skills = [];
        foreach (openmira_get_cpt_skill_posts() as $post) {
            $skill = openmira_skill_from_cpt_post($post);
            if ($skill === null) {
                continue;
            }
            $skill_id = (string) $skill['id'];
            if ($skill_id === '') {
                continue;
            }
            $skills[$skill_id] = $skill;
        }

        return $skills;
    }

    public function get_skill(string $id): ?array
    {
        $post = openmira_get_cpt_skill_post($id);
        return $post instanceof WP_Post ? openmira_skill_from_cpt_post($post) : null;
    }
}

add_filter('openmira_skill_sources', static function (array $sources): array {
    $sources['cpt'] = new OpenMira_CPT_Skill_Source();
    return $sources;
});

/**
 * Return all user Skill posts.
 *
 * @param list<string> $post_statuses
 * @return list<WP_Post>
 */
function openmira_get_cpt_skill_posts(array $post_statuses = ['publish', 'draft', 'private']): array
{
    if (!post_type_exists(OPENMIRA_SKILL_POST_TYPE)) {
        return [];
    }

    $statuses = array_values(array_filter(
        $post_statuses,
        static fn(mixed $status): bool => is_string($status) && $status !== '',
    ));
    if ($statuses === []) {
        $statuses = ['publish', 'draft', 'private'];
    }

    $raw_posts = get_posts([
        'post_type' => OPENMIRA_SKILL_POST_TYPE,
        'post_status' => $statuses,
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    ]);

    $posts = [];
    foreach (is_array($raw_posts) ? $raw_posts : [] as $post) {
        if ($post instanceof WP_Post) {
            $posts[] = $post;
        }
    }

    return $posts;
}

/**
 * Return one user Skill post by canonical Skill ID.
 */
function openmira_get_cpt_skill_post(string $skill_id, bool $include_trash = false): ?WP_Post
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid) || !post_type_exists(OPENMIRA_SKILL_POST_TYPE)) {
        return null;
    }

    $raw_posts = get_posts([
        'post_type' => OPENMIRA_SKILL_POST_TYPE,
        'post_status' => $include_trash ? ['publish', 'draft', 'private', 'trash'] : ['publish', 'draft', 'private'],
        'posts_per_page' => 1,
        'no_found_rows' => true,
        'meta_key' => OPENMIRA_SKILL_ID_META,
        'meta_value' => $skill_id,
    ]);
    $posts = is_array($raw_posts) ? array_values($raw_posts) : [];
    $post = $posts[0] ?? null;

    return $post instanceof WP_Post ? $post : null;
}

/**
 * Convert a CPT post to the canonical skill array.
 *
 * @return array<string, mixed>|null
 */
function openmira_skill_from_cpt_post(WP_Post $post): ?array
{
    if ($post->post_type !== OPENMIRA_SKILL_POST_TYPE) {
        return null;
    }

    $skill_id = (string) get_post_meta($post->ID, OPENMIRA_SKILL_ID_META, true);
    if (is_wp_error(openmira_validate_skill_id($skill_id))) {
        return null;
    }

    return openmira_normalize_skill([
        'id' => $skill_id,
        'title' => $post->post_title !== '' ? $post->post_title : $skill_id,
        'description' => $post->post_excerpt,
        'body' => $post->post_content,
        'path' => '',
        'source' => 'cpt',
        'post_id' => $post->ID,
        'enabled' => openmira_is_cpt_skill_prompt_enabled($post->ID),
    ]);
}

/**
 * Whether one CPT Skill should be registered as an MCP Prompt.
 */
function openmira_is_cpt_skill_prompt_enabled(int $post_id): bool
{
    $value = get_post_meta($post_id, OPENMIRA_SKILL_ENABLE_PROMPT_META, true);
    if ($value === '') {
        return true;
    }
    if (!is_bool($value) && !is_int($value) && !is_string($value)) {
        return true;
    }

    return rest_sanitize_boolean($value) === true;
}

/**
 * Save a user Skill into the CPT store.
 *
 * @param array{id?: string, title?: string, description?: string, body?: string, enabled?: bool} $input
 * @return array{status: string, id: string, post_id: int}|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
function openmira_upsert_cpt_skill(array $input, bool $require_capability = true): array|WP_Error
{
    if ($require_capability && !current_user_can('manage_options')) {
        return new WP_Error('openmira_skill_permission_denied', 'You do not have permission to save Open Mira Skills.');
    }

    $skill_id = $input['id'] ?? '';
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $body = $input['body'] ?? '';
    if ($title === '') {
        return new WP_Error('openmira_skill_missing_title', 'Skill title is required.');
    }
    if ($description === '') {
        return new WP_Error('openmira_skill_missing_description', 'Skill description is required.');
    }
    if ($body === '') {
        return new WP_Error('openmira_skill_missing_body', 'Skill body is required.');
    }
    if (strlen($body) > OPENMIRA_SKILL_BODY_MAX_BYTES) {
        return new WP_Error('openmira_skill_body_too_large', 'Skill body must not exceed 64 KB.');
    }

    $existing = openmira_get_cpt_skill_post($skill_id);
    $postarr = [
        'post_type' => OPENMIRA_SKILL_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $title,
        'post_excerpt' => $description,
        'post_content' => $body,
        'meta_input' => [
            OPENMIRA_SKILL_ID_META => $skill_id,
            OPENMIRA_SKILL_ENABLE_PROMPT_META => ($input['enabled'] ?? true) !== false ? '1' : '0',
        ],
    ];
    if ($existing instanceof WP_Post) {
        $postarr['ID'] = $existing->ID;
    }

    // @mago-expect analysis:possibly-invalid-argument
    $post_id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    return [
        'status' => $existing instanceof WP_Post ? 'updated' : 'created',
        'id' => $skill_id,
        'post_id' => (int) $post_id,
    ];
}

/**
 * Delete a CPT user Skill.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_delete_cpt_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $post = openmira_get_cpt_skill_post($skill_id, include_trash: true);
    if (!$post instanceof WP_Post) {
        return new WP_Error(
            'openmira_skill_not_user_editable',
            'Only CPT-backed user skills can be deleted. Built-in skills are read-only.',
        );
    }

    $deleted = wp_delete_post($post->ID, force_delete: true);
    if (!$deleted instanceof WP_Post) {
        return new WP_Error('openmira_skill_delete_failed', 'Could not delete the user skill.');
    }

    return ['status' => 'deleted', 'id' => $skill_id];
}

/**
 * Move a CPT user Skill to the native WordPress trash.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_trash_cpt_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $post = openmira_get_cpt_skill_post($skill_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error(
            'openmira_skill_not_user_editable',
            'Only active CPT-backed user skills can be trashed. Built-in skills are read-only.',
        );
    }

    $trashed = wp_trash_post($post->ID);
    if (!$trashed instanceof WP_Post) {
        return new WP_Error('openmira_skill_trash_failed', 'Could not trash the user skill.');
    }

    return ['status' => 'trashed', 'id' => $skill_id];
}

/**
 * Restore a CPT user Skill from the native WordPress trash.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_restore_cpt_skill(string $skill_id): array|WP_Error
{
    $valid = openmira_validate_skill_id($skill_id);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $post = openmira_get_cpt_skill_post($skill_id, include_trash: true);
    if (!$post instanceof WP_Post || $post->post_status !== 'trash') {
        return new WP_Error('openmira_skill_not_trashed', 'Only trashed CPT-backed user skills can be restored.');
    }

    $restored = wp_untrash_post($post->ID);
    if (!$restored instanceof WP_Post) {
        return new WP_Error('openmira_skill_restore_failed', 'Could not restore the user skill.');
    }

    return ['status' => 'restored', 'id' => $skill_id];
}

/**
 * Toggle prompt registration for one CPT user Skill.
 *
 * @return array{status: string, id: string}|WP_Error
 */
function openmira_set_cpt_skill_prompt_enabled(string $skill_id, bool $enabled): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'openmira_skill_permission_denied',
            'You do not have permission to update Open Mira Skills.',
        );
    }

    $post = openmira_get_cpt_skill_post($skill_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('openmira_skill_not_user_editable', 'Only CPT-backed user skills can be toggled.');
    }

    update_post_meta($post->ID, OPENMIRA_SKILL_ENABLE_PROMPT_META, $enabled ? '1' : '0');
    return ['status' => $enabled ? 'enabled' : 'disabled', 'id' => $skill_id];
}

/**
 * Import legacy 1.5.x filesystem user skills into CPT storage once.
 *
 * @return array{migrated: int, skipped: int}|WP_Error
 */
function openmira_migrate_legacy_user_skills_to_cpt(): array|WP_Error
{
    if (get_option(OPENMIRA_SKILLS_CPT_MIGRATION_OPTION) === '1') {
        return ['migrated' => 0, 'skipped' => 0];
    }

    $legacy_dir = openmira_user_skills_dir();
    $legacy_skills = is_dir($legacy_dir)
        ? openmira_scan_skills_directory($legacy_dir, source: 'legacy-filesystem')
        : [];
    $counts = ['migrated' => 0, 'skipped' => 0];
    foreach ($legacy_skills as $skill) {
        $skill_id = $skill['id'];
        if (openmira_get_cpt_skill_post($skill_id) instanceof WP_Post) {
            $counts['skipped']++;
            continue;
        }

        $result = openmira_upsert_cpt_skill([
            'id' => $skill_id,
            'title' => $skill['title'],
            'description' => $skill['description'],
            'body' => $skill['body'],
            'enabled' => $skill['enabled'] !== false,
        ], require_capability: false);
        if (is_wp_error($result)) {
            return $result;
        }
        $counts['migrated']++;
    }

    update_option(OPENMIRA_SKILLS_CPT_MIGRATION_OPTION, value: '1', autoload: false);
    return $counts;
}

/**
 * Whether legacy 1.5.x user skill files still exist on disk.
 */
function openmira_has_legacy_user_skill_files(): bool
{
    $legacy_dir = openmira_user_skills_dir();
    return is_dir($legacy_dir) && openmira_scan_skills_directory($legacy_dir, source: 'legacy-filesystem') !== [];
}

add_action(
    'init',
    static function (): void {
        $result = openmira_migrate_legacy_user_skills_to_cpt();
        if (is_wp_error($result)) {
            update_option('openmira_skills_cpt_migration_error', $result->get_error_message(), autoload: false);
        }
    },
    priority: 20,
);
