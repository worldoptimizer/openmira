<?php

declare(strict_types=1);

/**
 * Shared storage helpers for persistent Open Mira project memory.
 */

if (!defined('ABSPATH')) {
    exit();
}

const OPENMIRA_MEMORY_OPTION = 'openmira_project_memory';

/**
 * Return all memory entries.
 *
 * @return array<string, array<array-key, mixed>>
 */
function openmira_get_memory_entries(): array
{
    // @mago-expect analysis:mixed-assignment
    $stored_entries = get_option(OPENMIRA_MEMORY_OPTION, default_value: []);
    if (!is_array($stored_entries)) {
        return [];
    }

    $entries = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($stored_entries as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }
        $entries[$key] = $entry;
    }

    return $entries;
}

/**
 * Persist all memory entries.
 *
 * @param array<string, array<array-key, mixed>> $entries
 */
function openmira_update_memory_entries(array $entries): void
{
    update_option(OPENMIRA_MEMORY_OPTION, $entries, autoload: false);
}

/**
 * Validate a memory key.
 */
function openmira_validate_memory_key(string $key): bool|WP_Error
{
    if (preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $key) !== 1) {
        return new WP_Error(
            'invalid_memory_key',
            'Memory key must be 1-80 characters and contain only lowercase letters, numbers, dots, underscores, and hyphens.',
        );
    }

    return true;
}

/**
 * Read memory entries.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_read_memory(array $input = []): array|WP_Error
{
    $entries = openmira_get_memory_entries();
    $key = (string) ($input['key'] ?? '');
    if ($key !== '') {
        $valid = openmira_validate_memory_key($key);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $entries = array_key_exists($key, $entries) ? [$key => $entries[$key]] : [];
    }

    ksort($entries);

    return [
        'entries' => $entries,
        'count' => count($entries),
    ];
}

/**
 * Write a memory entry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_write_memory(array $input): array|WP_Error
{
    $key = (string) ($input['key'] ?? '');
    $value = (string) ($input['value'] ?? '');

    $valid = openmira_validate_memory_key($key);
    if (is_wp_error($valid)) {
        return $valid;
    }
    if (strlen($value) > 20_000) {
        return new WP_Error('memory_value_too_large', 'Memory value must not exceed 20000 bytes.');
    }

    $entries = openmira_get_memory_entries();
    $created = !array_key_exists($key, $entries);
    $entry = [
        'value' => $value,
        'updated_at' => current_time(type: 'mysql', gmt: true),
        'updated_by' => get_current_user_id(),
    ];
    $entries[$key] = $entry;
    ksort($entries);
    openmira_update_memory_entries($entries);

    return [
        'key' => $key,
        'created' => $created,
        'entry' => $entry,
    ];
}

/**
 * Delete a memory entry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function openmira_delete_memory(array $input): array|WP_Error
{
    $key = (string) ($input['key'] ?? '');
    $valid = openmira_validate_memory_key($key);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $entries = openmira_get_memory_entries();
    $deleted = array_key_exists($key, $entries);
    if ($deleted) {
        unset($entries[$key]);
        openmira_update_memory_entries($entries);
    }

    return [
        'key' => $key,
        'deleted' => $deleted,
    ];
}
