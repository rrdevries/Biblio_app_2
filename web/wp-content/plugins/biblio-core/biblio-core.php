<?php
/**
 * Plugin Name: Biblio Core
 * Description: Core application layer for Biblio V2.
 * Version: 2.1.0
 * Requires PHP: 8.3
 * Text Domain: biblio-core
 */

declare(strict_types=1);

defined("ABSPATH") || exit;

$autoloadPath = __DIR__ . "/vendor/autoload.php";

if (!is_readable($autoloadPath)) {
    $message = "Biblio Core dependencies are missing. Run: "
        . "ddev composer --working-dir=web/wp-content/plugins/biblio-core install";

    error_log($message);

    add_action("admin_notices", static function () use ($message): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html($message)
            . "</p></div>";
    });

    return;
}

require_once $autoloadPath;

\Biblio\Core\Plugin::boot();
