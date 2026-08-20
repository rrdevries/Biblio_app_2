<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Classification\ClassificationSeedEvolutionService;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
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
        $activityEvents = $this->tableNames->libraryActivityEvents();
        $contextGenres = $this->tableNames->libraryCatalogContextGenres();
        $contextSubjects = $this->tableNames->libraryCatalogContextSubjects();
        $contexts = $this->tableNames->libraryCatalogContexts();
        $bookTypes = $this->tableNames->libraryBookTypes();
        $genres = $this->tableNames->libraryGenres();
        $subjects = $this->tableNames->librarySubjects();
        $readingRounds = $this->tableNames->readingRounds();
        $externalLoans = $this->tableNames->externalLoans();
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $memberships = $this->tableNames->memberships();
        $libraries = $this->tableNames->libraries();

        foreach ([
            $activityEvents,
            $contextGenres,
            $contextSubjects,
            $contexts,
            $bookTypes,
            $genres,
            $subjects,
        ] as $schema1001Table) {
            if ($this->tableExists($schema1001Table)) {
                $this->database->query("DELETE FROM `{$schema1001Table}`");
            }
        }
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

    protected function classificationSeedEvolution(): ClassificationSeedEvolutionService
    {
        return WpdbClassificationSeedEvolutionFactory::create(
            $this->database,
            $this->tableNames
        );
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        )) === 1;
    }
}
