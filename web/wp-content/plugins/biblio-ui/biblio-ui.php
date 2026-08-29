<?php
/**
 * Plugin Name: Biblio UI
 * Description: Browser presentation adapter for Biblio V2.
 * Version: 0.2.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: biblio-ui
 */

declare(strict_types=1);

defined("ABSPATH") || exit;

require_once __DIR__ . "/src/LibraryAppShortcode.php";
require_once __DIR__ . "/src/Plugin.php";

(new \Biblio\UI\Plugin(__FILE__))->boot();
