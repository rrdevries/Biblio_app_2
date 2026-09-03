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
        $privateNotes = $this->tableNames->privateNotes();
        $ratings = $this->tableNames->ratings();
        $reviews = $this->tableNames->reviews();
        $publications = $this->tableNames->contributionPublications();
        $nextReadingEntries = $this->tableNames->nextReadingEntries();
        $nextReadingLists = $this->tableNames->nextReadingLists();
        $nextReadingUndo = $this->tableNames->nextReadingUndo();
        $externalLoans = $this->tableNames->externalLoans();
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $workContributors = $this->tableNames->workContributors();
        $authors = $this->tableNames->authors();
        $workSeries = $this->tableNames->workSeries();
        $series = $this->tableNames->series();
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
        foreach ([$publications, $ratings, $reviews] as $assessmentTable) {
            if ($this->tableExists($assessmentTable)) {
                $this->database->query("DELETE FROM `{$assessmentTable}`");
            }
        }
        if ($this->tableExists($nextReadingUndo)) {
            $this->database->query("DELETE FROM `{$nextReadingUndo}`");
        }
        if ($this->tableExists($nextReadingEntries)) {
            $this->database->query("DELETE FROM `{$nextReadingEntries}`");
        }
        if ($this->tableExists($nextReadingLists)) {
            $this->database->query("DELETE FROM `{$nextReadingLists}`");
        }
        if ($this->tableExists($privateNotes)) {
            $this->database->query("DELETE FROM `{$privateNotes}`");
        }
        $this->database->query("DELETE FROM `{$readingRounds}`");
        $this->database->query("DELETE FROM `{$externalLoans}`");
        $this->database->query("DELETE FROM `{$items}`");
        $this->database->query("DELETE FROM `{$editions}`");
        if ($this->tableExists($workContributors)) {
            $this->database->query("DELETE FROM `{$workContributors}`");
        }
        if ($this->tableExists($workSeries)) {
            $this->database->query("DELETE FROM `{$workSeries}`");
        }
        if ($this->tableExists($authors)) {
            $this->database->query("DELETE FROM `{$authors}`");
        }
        if ($this->tableExists($series)) {
            $this->database->query("DELETE FROM `{$series}`");
        }
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
