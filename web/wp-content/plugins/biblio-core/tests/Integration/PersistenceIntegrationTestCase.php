<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Classification\ClassificationSeedEvolutionService;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
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
        $itemArchivePeriods = $this->tableNames->itemArchivePeriods();
        $collectionMemberships = $this->tableNames->collectionMemberships();
        $collections = $this->tableNames->collections();
        $identifierClaims = $this->tableNames->editionIdentifierClaims();
        $metadataProvenance = $this->tableNames->editionMetadataProvenance();
        $metadataFieldEvidence = $this->tableNames->metadataFieldEvidence();
        $metadataFieldValues = $this->tableNames->metadataFieldValues();
        $metadataFieldStates = $this->tableNames->metadataFieldStates();
        $metadataLookupCandidates = $this->tableNames->metadataLookupCandidates();
        $metadataLookupSnapshots = $this->tableNames->metadataLookupSnapshots();
        $bibliographicDiscoveryCandidates = $this->tableNames
            ->bibliographicDiscoveryCandidates();
        $bibliographicDiscoverySnapshots = $this->tableNames
            ->bibliographicDiscoverySnapshots();
        $bibliographicProviderIdentities = $this->tableNames
            ->bibliographicProviderIdentities();
        $metadataUserObservations = $this->tableNames->metadataUserObservations();
        $migrationMappings = $this->tableNames->migrationTargetMappings();
        $migrationQuarantine = $this->tableNames->migrationQuarantine();
        $migrationPreservations = $this->tableNames->migrationPreservations();
        $migrationObservations = $this->tableNames->migrationSourceObservations();
        $migrationRunLocks = $this->tableNames->migrationRunLocks();
        $migrationRuns = $this->tableNames->migrationRuns();
        $personalReadingTruths = $this->tableNames->personalReadingTruths();
        $personalWorkReadingLocks = $this->tableNames->personalWorkReadingLocks();
        $wishlistEntries = $this->tableNames->wishlistEntries();
        $wishlistEntryHistory = $this->tableNames->wishlistEntryHistory();
        $wishlistWorkStates = $this->tableNames->wishlistWorkStates();
        $locations = $this->tableNames->locations();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $workContributors = $this->tableNames->workContributors();
        $authors = $this->tableNames->authors();
        $workSeries = $this->tableNames->workSeries();
        $series = $this->tableNames->series();
        $workAlternateTitles = $this->tableNames->workAlternateTitles();
        $workContainments = $this->tableNames->workContainments();
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $memberships = $this->tableNames->memberships();
        $libraries = $this->tableNames->libraries();

        foreach ([
            $migrationMappings,
            $migrationQuarantine,
            $migrationPreservations,
            $migrationObservations,
            $migrationRunLocks,
            $migrationRuns,
        ] as $migrationTable) {
            if ($this->tableExists($migrationTable)) {
                $this->database->query("DELETE FROM " . $migrationTable);
            }
        }
        foreach ([$personalReadingTruths, $personalWorkReadingLocks] as $readingTable) {
            if ($this->tableExists($readingTable)) {
                $this->database->query("DELETE FROM `{$readingTable}`");
            }
        }
        foreach ([$wishlistEntryHistory, $wishlistEntries, $wishlistWorkStates] as $wishlistTable) {
            if ($this->tableExists($wishlistTable)) {
                $this->database->query("DELETE FROM `{$wishlistTable}`");
            }
        }

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
        if ($this->tableExists($itemArchivePeriods)) {
            $this->database->query("DELETE FROM `{$itemArchivePeriods}`");
        }
        if ($this->tableExists($collectionMemberships)) {
            $this->database->query("DELETE FROM `{$collectionMemberships}`");
        }
        if ($this->tableExists($collections)) {
            $this->database->query("DELETE FROM `{$collections}`");
        }
        if ($this->tableExists($metadataUserObservations)) {
            $this->database->query("DELETE FROM `{$metadataUserObservations}`");
        }
        $this->database->query("DELETE FROM `{$items}`");
        if ($this->tableExists($metadataProvenance)) {
            $this->database->query("DELETE FROM `{$metadataProvenance}`");
        }
        if ($this->tableExists($metadataFieldEvidence)) {
            $this->database->query("DELETE FROM `{$metadataFieldEvidence}`");
        }
        if ($this->tableExists($metadataFieldValues)) {
            $this->database->query("DELETE FROM `{$metadataFieldValues}`");
        }
        if ($this->tableExists($metadataFieldStates)) {
            $this->database->query("DELETE FROM `{$metadataFieldStates}`");
        }
        if ($this->tableExists($metadataLookupCandidates)) {
            $this->database->query("DELETE FROM `{$metadataLookupCandidates}`");
        }
        if ($this->tableExists($metadataLookupSnapshots)) {
            $this->database->query("DELETE FROM `{$metadataLookupSnapshots}`");
        }
        if ($this->tableExists($bibliographicDiscoveryCandidates)) {
            $this->database->query("DELETE FROM `{$bibliographicDiscoveryCandidates}`");
        }
        if ($this->tableExists($bibliographicDiscoverySnapshots)) {
            $this->database->query("DELETE FROM `{$bibliographicDiscoverySnapshots}`");
        }
        if ($this->tableExists($bibliographicProviderIdentities)) {
            $this->database->query("DELETE FROM `{$bibliographicProviderIdentities}`");
        }
        if ($this->tableExists($identifierClaims)) {
            $this->database->query("DELETE FROM `{$identifierClaims}`");
        }
        if ($this->tableExists($locations)) {
            $this->database->query("DELETE FROM `{$locations}`");
        }
        $this->database->query("DELETE FROM `{$editions}`");
        if ($this->tableExists($workContributors)) {
            $this->database->query("DELETE FROM `{$workContributors}`");
        }
        if ($this->tableExists($workSeries)) {
            $this->database->query("DELETE FROM `{$workSeries}`");
        }
        if ($this->tableExists($workAlternateTitles)) {
            $this->database->query("DELETE FROM `{$workAlternateTitles}`");
        }
        if ($this->tableExists($workContainments)) {
            $this->database->query("DELETE FROM `{$workContainments}`");
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

    protected function setHistoricalSchemaVersion(int $version): void
    {
        if ($version < 1023) {
            foreach (array_reverse($this->tableNames->schema1023Additions()) as $table) {
                $this->database->query("DROP TABLE IF EXISTS `{$table}`");
            }

            $evidence = $this->tableNames->metadataFieldEvidence();
            if ($this->tableExists($evidence)) {
                $columnType = strtolower((string) $this->database->get_var(
                    $this->database->prepare(
                        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS "
                            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
                            . "AND COLUMN_NAME = 'queried_identifier'",
                        DB_NAME,
                        $evidence
                    )
                ));

                if ($columnType === "varchar(100)") {
                    $this->database->query(
                        "ALTER TABLE `{$evidence}` "
                            . "DROP CONSTRAINT metadata_field_evidence_match_supported,"
                            . "DROP CONSTRAINT metadata_field_evidence_query_valid,"
                            . "MODIFY queried_identifier VARCHAR(13) "
                            . "CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                            . "ADD CONSTRAINT metadata_field_evidence_match_supported "
                            . "CHECK (match_method='exact_isbn'),"
                            . "ADD CONSTRAINT metadata_field_evidence_query_valid "
                            . "CHECK ((queried_identifier_type='isbn_10' "
                            . "AND queried_identifier REGEXP '^[0-9]{9}[0-9X]$') "
                            . "OR (queried_identifier_type='isbn_13' "
                            . "AND queried_identifier REGEXP '^97[89][0-9]{10}$'))"
                    );
                }
            }
        }

        update_option(CoreSchemaMigrator::VERSION_OPTION, (string) $version, false);
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
