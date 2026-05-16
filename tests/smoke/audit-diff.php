<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

if (!function_exists('openmira_record_audit_event') || !function_exists('openmira_render_audit_diff_cell')) {
    fwrite(STDERR, "Open Mira audit helpers are not loaded.\n");
    exit(1);
}

openmira_clear_audit_log();

$diff = "--- wp-content/example.php\n+++ wp-content/example.php\n@@\n-old\n+new\n";
$event = openmira_record_audit_event([
    'ability' => 'openmira/write-file',
    'operation' => 'write',
    'target_path' => 'wp-content/example.php',
    'status' => 'success',
    'diff_summary' => '1 added, 1 removed',
    'diff' => $diff,
]);

$log = openmira_get_audit_log();
$stored = $log[0] ?? [];
if (($stored['id'] ?? '') !== ($event['id'] ?? '') || openmira_audit_event_diff($stored) !== $diff) {
    fwrite(STDERR, "Audit log did not preserve the full diff.\n");
    exit(1);
}

ob_start();
openmira_render_audit_diff_cell($stored);
$html = (string) ob_get_clean();
if (!str_contains($html, '<details') || !str_contains($html, '1 added, 1 removed') || !str_contains($html, '-old')) {
    fwrite(STDERR, "Audit diff cell did not render an expandable diff.\n");
    exit(1);
}

$large = str_repeat('+changed line' . "\n", 7000);
$compacted = openmira_compact_audit_diff($large);
if (strlen($compacted) > 61000 || !str_contains($compacted, '@@ audit diff truncated @@')) {
    fwrite(STDERR, "Audit diff compaction did not bound large diffs.\n");
    exit(1);
}

openmira_clear_audit_log();

echo wp_json_encode([
    'status' => 'ok',
    'diff_bytes' => strlen($diff),
    'compacted_bytes' => strlen($compacted),
], JSON_PRETTY_PRINT) . "\n";
