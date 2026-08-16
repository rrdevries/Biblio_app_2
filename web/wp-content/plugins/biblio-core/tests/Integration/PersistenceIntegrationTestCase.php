<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\LibraryTableNames;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use wpdb;

abstract class PersistenceIntegrationTestCase extends TestCase
{
    protected wpdb $database;
    protected LibraryTableNames $tableNames;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        if (!$wpdb instanceof wpdb || DB_NAME !== "biblio_core_test") {
            throw new RuntimeException(
                "Persistence tests require the isolated test database."
            );
        }

        $this->database = $wpdb;
        $this->tableNames = new LibraryTableNames($wpdb->prefix);
        $this->resetLibraryTables();
    }

    protected function tearDown(): void
    {
        try {
            $this->resetLibraryTables();
        } finally {
            parent::tearDown();
        }
    }

    protected function resetLibraryTables(): void
    {
        $memberships = $this->tableNames->memberships();
        $libraries = $this->tableNames->libraries();

        $this->database->query("DELETE FROM `{$memberships}`");
        $this->database->query("DELETE FROM `{$libraries}`");
    }
}
