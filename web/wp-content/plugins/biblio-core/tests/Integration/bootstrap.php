<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

$expectedDatabase = "biblio_core_test";
$configuredDatabase = getenv("DB_NAME");

if ($configuredDatabase !== $expectedDatabase) {
    throw new \RuntimeException(
        "Integration tests require the isolated database "
        . $expectedDatabase
        . "; received "
        . var_export($configuredDatabase, true)
        . "."
    );
}

$wordpressRoot = dirname(__DIR__, 5);
$wordpressBootstrap = $wordpressRoot . "/wp-load.php";

if (!is_readable($wordpressBootstrap)) {
    throw new \RuntimeException(
        "WordPress bootstrap not found at " . $wordpressBootstrap . "."
    );
}

defined("WP_USE_THEMES") || define("WP_USE_THEMES", false);

require_once $wordpressBootstrap;

if (!defined("DB_NAME") || DB_NAME !== $expectedDatabase) {
    throw new \RuntimeException(
        "WordPress did not bootstrap against the isolated test database."
    );
}

$tableNames = new \Biblio\Core\Infrastructure\Persistence\WordPress\LibraryTableNames(
    $wpdb->prefix
);
$schemaMigrator = new \Biblio\Core\Infrastructure\Persistence\WordPress\LibrarySchemaMigrator(
    $wpdb,
    $tableNames
);
$schemaMigrator->migrate();

require_once __DIR__ . "/PersistenceIntegrationTestCase.php";
