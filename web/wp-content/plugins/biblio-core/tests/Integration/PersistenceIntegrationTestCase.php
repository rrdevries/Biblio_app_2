<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use wpdb;

abstract class PersistenceIntegrationTestCase extends TestCase
{
    protected wpdb $database;
    protected CoreTableNames $tableNames;

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
        $this->tableNames = new CoreTableNames($wpdb->prefix);
        $this->resetCoreTables();
    }

    protected function tearDown(): void
    {
        try {
            $this->resetCoreTables();
        } finally {
            parent::tearDown();
        }
    }

    protected function resetCoreTables(): void
    {
        $readingRounds = $this->tableNames->readingRounds();
        $externalLoans = $this->tableNames->externalLoans();
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $memberships = $this->tableNames->memberships();
        $libraries = $this->tableNames->libraries();

        $this->database->query("DELETE FROM `{$readingRounds}`");
        $this->database->query("DELETE FROM `{$externalLoans}`");
        $this->database->query("DELETE FROM `{$items}`");
        $this->database->query("DELETE FROM `{$editions}`");
        $this->database->query("DELETE FROM `{$works}`");
        $this->database->query(
            "DELETE FROM `{$personalLibraryDesignations}`"
        );
        $this->database->query("DELETE FROM `{$memberships}`");
        $this->database->query("DELETE FROM `{$libraries}`");
    }
}
