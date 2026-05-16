<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file with wp eval-file inside WordPress.\n");
    exit(1);
}

wp_set_current_user(1);

if (!function_exists('openmira_permission_callback')) {
    fwrite(STDERR, "Open Mira permission callback is not loaded.\n");
    exit(1);
}

$allowed = openmira_permission_callback('openmira/read-file');
if ($allowed !== true) {
    fwrite(STDERR, "Default admin ability permission was not granted.\n");
    exit(1);
}

$deny_filter = static function (string $capability, ?string $ability_name): string {
    if ($ability_name === 'openmira/read-file') {
        return 'do_not_allow';
    }

    return $capability;
};
add_filter('openmira_ability_capability', $deny_filter, 10, 2);

$denied = openmira_permission_callback('openmira/read-file');
remove_filter('openmira_ability_capability', $deny_filter, 10);

if ($denied !== false) {
    fwrite(STDERR, "Per-ability capability filter did not deny read-file.\n");
    exit(1);
}

$block_filter = static fn(bool $blocked, ?string $_ability_name): bool => true;
$production_filter = static fn(bool $looks_like_production, ?string $_ability_name): bool => true;
add_filter('openmira_block_production', $block_filter, 10, 2);
add_filter('openmira_is_production_site', $production_filter, 10, 2);

$blocked = openmira_permission_callback('openmira/read-file');

remove_filter('openmira_block_production', $block_filter, 10);
remove_filter('openmira_is_production_site', $production_filter, 10);

if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'openmira_production_blocked') {
    fwrite(STDERR, "Production hard-block did not return the expected WP_Error.\n");
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'default_allowed' => $allowed,
    'capability_denied' => $denied,
    'production_error' => $blocked->get_error_code(),
], JSON_PRETTY_PRINT) . "\n";
