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

require_once __DIR__ . "/vendor/autoload.php";

\Biblio\Core\Plugin::boot();
