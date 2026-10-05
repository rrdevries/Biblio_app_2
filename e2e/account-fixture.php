<?php

use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;

global $wpdb;

// Disposable account journey fixtures. Refuse every other environment or unmarked collision.
if (!defined('WP_CLI') || getenv('BIBLIO_E2E_ALLOW_FIXTURES') !== '1'
    || wp_get_environment_type() !== 'local' || getenv('DDEV_PROJECT') !== 'biblio-v2'
    || getenv('IS_DDEV_PROJECT') !== 'true') {
    throw new RuntimeException('Account fixtures require explicit opt-in and the exact local DDEV project.');
}
foreach ([home_url('/'), site_url('/'), getenv('DDEV_PRIMARY_URL')] as $url) {
    if (wp_parse_url($url, PHP_URL_HOST) !== 'biblio-v2.ddev.site' || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Account fixture host mismatch.');
    }
}
$accountFixtureAction = $args[0] ?? '';
if (!in_array($accountFixtureAction, ['setup', 'setup-mail-pending', 'cleanup', 'state', 'state-mail-pending', 'fingerprint'], true)) { throw new RuntimeException('Invalid account fixture action.'); }
$accountFixtureNames = ['biblio_entry_e2e_owner', 'biblio_entry_e2e_other'];
$accountFixtureTables = new CoreTableNames($wpdb->prefix);
foreach ($accountFixtureNames as $username) {
    $user = get_user_by('login', $username);
    if ($user instanceof WP_User && get_user_meta($user->ID, 'biblio_entry_e2e', true) !== 'entry-05') {
        throw new RuntimeException('Unmarked account fixture collision; refusing all mutations.');
    }
}
if ($accountFixtureAction === 'fingerprint') {
    $payload = [];
    foreach ($accountFixtureTables->schema1027() as $table) {
        $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
        if ($wpdb->last_error !== '') { throw new RuntimeException('Fingerprint read failed.'); }
        $serialized = [];
        foreach ($rows as $row) { ksort($row); $serialized[] = wp_json_encode($row); }
        sort($serialized); $payload[$table] = $serialized;
    }
    $payload['wordpress_users'] = $wpdb->get_results("SELECT ID,user_login FROM `{$wpdb->users}` ORDER BY ID", ARRAY_A);
    WP_CLI::line(hash('sha256', wp_json_encode($payload))); return;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ($accountFixtureAction === 'cleanup') {
    foreach ($accountFixtureNames as $username) {
        $user = get_user_by('login', $username);
        if (!$user instanceof WP_User) { continue; }
        $id = $wpdb->get_var($wpdb->prepare("SELECT library_id FROM `{$accountFixtureTables->personalLibraryDesignations()}` WHERE user_id=%s", (string) $user->ID));
        $memberships = $wpdb->get_results($wpdb->prepare("SELECT library_id,user_id,management_role FROM `{$accountFixtureTables->memberships()}` WHERE user_id=%s", (string) $user->ID), ARRAY_A);
        if ($id === null && $memberships !== []) { throw new RuntimeException('Fixture membership is ambiguous.'); }
        if ($id !== null && (count($memberships) !== 1 || $memberships[0]['library_id'] !== $id || $memberships[0]['management_role'] !== 'owner'
            || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$accountFixtureTables->memberships()}` WHERE library_id=%s", $id)) !== 1
            || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$accountFixtureTables->items()}` WHERE library_id=%s", $id)) !== 0)) {
            throw new RuntimeException('Refusing cleanup of a nonempty or shared fixture Library.');
        }
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->delete($accountFixtureTables->accountPreparations(), ['user_id' => (string) $user->ID]) === false) { throw new RuntimeException('Preparation cleanup failed.'); }
            if ($id !== null) {
                foreach ([$accountFixtureTables->libraryCatalogContextGenres(), $accountFixtureTables->libraryCatalogContextSubjects(), $accountFixtureTables->libraryCatalogContexts(), $accountFixtureTables->libraryBookTypes(), $accountFixtureTables->libraryGenres(), $accountFixtureTables->librarySubjects(), $accountFixtureTables->personalLibraryDesignations(), $accountFixtureTables->memberships(), $accountFixtureTables->libraries()] as $table) {
                    if ($wpdb->delete($table, ['library_id' => $id]) === false) { throw new RuntimeException('Exact fixture Library cleanup failed.'); }
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Fixture cleanup commit failed.'); }
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
        if (!wp_delete_user($user->ID)) { throw new RuntimeException('Exact fixture account cleanup failed.'); }
    }
    WP_CLI::line('Account fixtures cleaned.'); return;
}
$accountFixtureAdmin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($accountFixtureAdmin === []) { throw new RuntimeException('No local account operator.'); }
wp_set_current_user((int) $accountFixtureAdmin[0]);
if (in_array($accountFixtureAction, ['setup', 'setup-mail-pending'], true)) {
    foreach ($accountFixtureNames as $username) {
        if (get_user_by('login', $username) !== false) { throw new RuntimeException('Cleanup account fixtures before setup.'); }
    }
    $accountFixturePassword = (string) getenv('BIBLIO_E2E_ACTOR_PASSWORD');
    if (strlen($accountFixturePassword) < 32) { throw new RuntimeException('Fixture password missing.'); }
    // Persist the test marker with the WordPress account, before Core provisioning runs.
    $marker = static function ($errors, $update, $user): void {
        $user->meta_input = ['biblio_entry_e2e' => 'entry-05'] + (array) ($user->meta_input ?? []);
    };
    add_action('user_profile_update_errors', $marker, 5, 3);
    $blockFixtureMail = static function ($shortCircuit, array $mail) {
        $recipients = is_array($mail['to']) ? $mail['to'] : array_map('trim', explode(',', (string) $mail['to']));
        return in_array('biblio_entry_e2e_owner@example.invalid', $recipients, true) ? false : $shortCircuit;
    };
    if ($accountFixtureAction === 'setup-mail-pending') {
        // Only the newly created, marked disposable account's notification fails.
        // This filter exists for this CLI request only, never in the subsequent browser request.
        add_filter('pre_wp_mail', $blockFixtureMail, 10, 2);
    }
    try {
        foreach ($accountFixtureAction === 'setup-mail-pending' ? [$accountFixtureNames[0]] : $accountFixtureNames as $username) {
            $_POST = ['user_login' => $username, 'email' => $username . '@example.invalid', 'role' => 'subscriber',
                'pass1' => $accountFixturePassword, 'pass2' => $accountFixturePassword, 'biblio_account' => '1',
                'send_user_notification' => '1', '_wpnonce_create-user' => wp_create_nonce('create-user')];
            $created = edit_user();
            if (!is_int($created)) { throw new RuntimeException('Fixture account creation failed.'); }
        }
    } finally {
        remove_action('user_profile_update_errors', $marker, 5);
        remove_filter('pre_wp_mail', $blockFixtureMail, 10);
        $_POST = [];
    }
}
$accountFixtureCore = (new ProductionComposition($wpdb))->application();
if (in_array($accountFixtureAction, ['setup-mail-pending', 'state-mail-pending'], true)) {
    $user = get_user_by('login', $accountFixtureNames[0]);
    if (!$user instanceof WP_User || get_user_meta($user->ID, 'biblio_entry_e2e', true) !== 'entry-05') {
        throw new RuntimeException('Mail recovery fixture missing or unmarked.');
    }
    $state = $accountFixtureCore->accountPreparation()->diagnose(new UserId((string) $user->ID));
    if ($accountFixtureAction === 'setup-mail-pending' && ($state['notified'] || $state['state'] !== 'ready' || count($state['owned_libraries']) !== 1 || !$state['needs_name'])) {
        throw new RuntimeException('Expected one prepared Library with unsent notification.');
    }
    WP_CLI::line(wp_json_encode([
        'account_id' => $user->ID,
        'username' => $user->user_login,
        'creation_redirect' => $accountFixtureAction === 'setup-mail-pending' ? apply_filters('wp_redirect', admin_url('users.php')) : null,
        'state' => $state,
    ]));
    return;
}
$result = [];
foreach ($accountFixtureNames as $username) {
    $user = get_user_by('login', $username);
    if (!$user instanceof WP_User) { throw new RuntimeException('Account fixture missing.'); }
    $result[$username] = $accountFixtureCore->accountPreparation()->diagnose(new UserId((string) $user->ID));
}
WP_CLI::line(wp_json_encode($result));
