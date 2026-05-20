<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_import_memory_entries')) {
    fwrite(STDERR, "Open Mira memory import helpers are not loaded.\n");
    exit(1);
}

$original = openmira_get_memory_entries();

try {
    openmira_update_memory_entries([
        'existing.memory' => [
            'value' => 'keep me',
            'updated_at' => '2026-05-20 00:00:00',
            'updated_by' => 1,
        ],
    ]);

    $payload = [
        'exported_at' => '2026-05-20 00:01:00',
        'entries' => [
            'existing.memory' => [
                'value' => 'overwrite me',
                'updated_at' => '2026-05-20 00:02:00',
                'updated_by' => 1,
            ],
            'new.memory' => [
                'value' => "# Imported\n\nMarkdown memory value.",
                'updated_at' => '2026-05-20 00:03:00',
                'updated_by' => 1,
            ],
        ],
    ];

    $skip_result = openmira_import_memory_entries($payload, true);
    if (is_wp_error($skip_result)) {
        fwrite(STDERR, $skip_result->get_error_message() . PHP_EOL);
        exit(1);
    }
    if ($skip_result !== ['imported' => 1, 'updated' => 0, 'skipped' => 1]) {
        fwrite(STDERR, 'Unexpected skip import result: ' . wp_json_encode($skip_result) . PHP_EOL);
        exit(1);
    }
    $after_skip = openmira_get_memory_entries();
    if (($after_skip['existing.memory']['value'] ?? '') !== 'keep me') {
        fwrite(STDERR, "Skip-existing import overwrote an existing key.\n");
        exit(1);
    }

    $overwrite_result = openmira_import_memory_entries($payload, false);
    if (is_wp_error($overwrite_result)) {
        fwrite(STDERR, $overwrite_result->get_error_message() . PHP_EOL);
        exit(1);
    }
    if ($overwrite_result !== ['imported' => 0, 'updated' => 2, 'skipped' => 0]) {
        fwrite(STDERR, 'Unexpected overwrite import result: ' . wp_json_encode($overwrite_result) . PHP_EOL);
        exit(1);
    }
    $after_overwrite = openmira_get_memory_entries();
    if (($after_overwrite['existing.memory']['value'] ?? '') !== 'overwrite me') {
        fwrite(STDERR, "Overwrite import did not update existing key.\n");
        exit(1);
    }
    if (($after_overwrite['new.memory']['value'] ?? '') !== "# Imported\n\nMarkdown memory value.") {
        fwrite(STDERR, "Imported memory value did not roundtrip.\n");
        exit(1);
    }

    echo wp_json_encode([
        'status' => 'ok',
        'skip_result' => $skip_result,
        'overwrite_result' => $overwrite_result,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    openmira_update_memory_entries($original);
}
