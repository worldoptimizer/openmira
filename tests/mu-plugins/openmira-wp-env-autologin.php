<?php
/**
 * Test-only autologin helper for wp-env browser and REST smoke tests.
 *
 * This file is copied into wp-content/mu-plugins by CI scripts. It is not part
 * of the release ZIP.
 */

add_action('init', static function (): void {
    if (!isset($_GET['openmira_ci_login']) || $_GET['openmira_ci_login'] !== '1') {
        return;
    }

    $user = get_user_by('login', 'admin');
    if (!$user) {
        wp_die('Open Mira CI autologin user not found.', 500);
    }

    wp_set_current_user((int) $user->ID);
    wp_set_auth_cookie((int) $user->ID);

    $page = isset($_GET['openmira_ci_redirect']) ? sanitize_key(wp_unslash($_GET['openmira_ci_redirect'])) : 'openmira';
    wp_safe_redirect(admin_url('admin.php?page=' . $page));
    exit;
});
