<?php

declare(strict_types=1);

// Definition-only WordPress libraries. Deliberately no wp-load/config, plugins,
// lifecycle, persistent cache drop-ins, HTTP requests or wp-cron execution.
// This file is used ONLY by the explicit standalone production CLI below.
define('ABSPATH', dirname(__DIR__) . '/web/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
define('MULTISITE', false);
define('WP_INSTALLING', true);
define('DISABLE_WP_CRON', true);
define('WP_CACHE', false);
// Core schema health queries require the explicitly supported production schema,
// including when recovery initially connects through information_schema.
define('DB_NAME', 'db');
define('WP_MEMORY_LIMIT', '1024M');
define('WP_MAX_MEMORY_LIMIT', '1024M');
// Only unrelated cursor-codec construction consumes these. Never read live secrets,
// issue login tokens or let wp_salt() create persistent fallback options.
foreach (['AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE'] as $scheme) {
    foreach (['KEY', 'SALT'] as $part) { define($scheme . '_' . $part, bin2hex(random_bytes(32))); }
}
$GLOBALS['blog_id'] = 1;
foreach (['version.php', 'compat-utf8.php', 'compat.php', 'load.php', 'plugin.php', 'default-constants.php',
    'class-wp-error.php', 'class-wp-list-util.php', 'class-wp-token-map.php', 'utf8.php', 'formatting.php', 'functions.php',
    'meta.php', 'option.php', 'class-wpdb.php', 'cache.php', 'capabilities.php', 'class-wp-roles.php', 'class-wp-role.php',
    'class-wp-user.php', 'user.php', 'pluggable.php', 'link-template.php', 'pomo/translations.php', 'l10n.php'] as $library) {
    require_once ABSPATH . WPINC . '/' . $library;
}
wp_initial_constants();
wp_cache_init();
// DDEV's fixed local root credentials stay in process memory, never argv/evidence.
$wpdb = new wpdb('root', 'root', $productionDatabase, 'db');
$wpdb->set_prefix('wp_');
$wpdb->suppress_errors(true);
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['table_prefix'] = 'wp_';
if (!in_array($mode ?? '', ['apply', 'restore', 'test-reset', 'test-reset-restore'], true)) {
    add_filter('query', static function (string $sql): string {
        if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|SET SESSION)\b/i', $sql) !== 1
            && preg_match('/^CREATE DATABASE `biblio_prod_probe_[a-f0-9]{12}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci$/D', $sql) !== 1) {
            throw new RuntimeException('production_preflight_write_denied');
        }
        return $sql;
    }, PHP_INT_MIN);
}
// No default WordPress filters/boot hooks are registered.
add_filter('pre_http_request', static function (): never { throw new RuntimeException('provider_request_forbidden'); }, PHP_INT_MIN);
