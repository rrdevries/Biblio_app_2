<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Catalog\Classification\ClassificationSeed;
use Biblio\Core\Catalog\Classification\DefaultClassificationSeeds;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1001Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1002Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1003Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaBaselineInstaller;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\WpTransientLifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use RuntimeException;

final readonly class FailOnLibrarySeedEvolution implements
    ClassificationSeedEvolution
{
    public function __construct(
        private ClassificationSeedEvolution $inner,
        private LibraryId $failureLibraryId
    ) {
    }

    public function evolve(LibraryId $libraryId): void
    {
        if ($libraryId->equals($this->failureLibraryId)) {
            throw new RuntimeException("Forced seed-bootstrap failure.");
        }

        $this->inner->evolve($libraryId);
    }

    public function isConverged(LibraryId $libraryId): bool
    {
        return $this->inner->isConverged($libraryId);
    }

    public function ambiguities(LibraryId $libraryId): array
    {
        return $this->inner->ambiguities($libraryId);
    }
}

final class Schema1002SeedEvolutionTest extends PersistenceIntegrationTestCase
{
    public function testReadingRoundUpgradePreservesLegacyTruthAndCanRetryBeforeVersionBump(): void
    {
        $this->downgradeToVersion1001();
        $this->schema1002Migrator()->migrate();
        $this->insertLibrary("legacy-round-library");
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "legacy-round-work",
            "work_title" => "Legacy Round Work",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "legacy-round-edition",
            "work_id" => "legacy-round-work",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "legacy-round-item",
            "library_id" => "legacy-round-library",
            "edition_id" => "legacy-round-edition",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => "legacy-round",
            "user_id" => 771,
            "work_id" => "legacy-round-work",
            "item_id" => "legacy-round-item",
            "external_loan_id" => null,
            "round_status" => "active",
            "started_at" => "2025-04-03 12:34:56.123456",
        ]);

        $migration = new CoreSchema1003Migration(
            $this->database,
            $this->tableNames
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1002, $this->schema1002Migrator()->installedVersion());

        $this->productionMigrator()->migrate();

        self::assertSame(1013, $this->productionMigrator()->installedVersion());
        self::assertSame([
            "legacy-round",
            "771",
            "legacy-round-work",
            "legacy-round-item",
            null,
            "2025-04-03 12:34:56.123456",
            null,
            "legacy_source_started",
            null,
            null,
            null,
            "1",
        ], $this->database->get_row(
            "SELECT reading_round_id, user_id, work_id, item_id, external_loan_id, "
            . "started_at, round_outcome, provenance, reading_started_year, "
            . "created_at, ended_at, round_version FROM "
            . "`{$this->tableNames->readingRounds()}` "
            . "WHERE reading_round_id = 'legacy-round'",
            ARRAY_N
        ));
    }

    public function testProductionUpgradeEvolvesEveryLibraryWithoutDdlOrAudit(): void
    {
        $this->downgradeToVersion1001();
        $this->insertLibrary("library-adoption");
        $this->insertLibrary("library-ambiguous");
        $this->insertBookType(
            "library-adoption",
            "local-reading-book",
            "Mijn Leesboek",
            "leesboek",
            "inactive"
        );
        $this->insertGenre(
            "library-adoption",
            "local-thriller",
            "Eigen thrillernaam",
            "thriller",
            "inactive",
            "genre.thriller"
        );
        $this->insertGenre(
            "library-ambiguous",
            "local-fantasy",
            "Lokale fantasy",
            "fantasy",
            "inactive",
            "genre.local_fantasy"
        );
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "existing-context-work",
            "work_title" => "Existing Context Work",
        ]);
        $this->database->insert(
            $this->tableNames->libraryCatalogContexts(),
            [
                "library_id" => "library-adoption",
                "work_id" => "existing-context-work",
                "book_type_id" => "local-reading-book",
                "context_version" => 7,
            ]
        );
        $schemaBefore = $this->schemaSnapshot();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1001", false);

        $this->schema1002Migrator()->migrate();

        self::assertSame(1002, $this->schema1002Migrator()->installedVersion());
        self::assertSame($schemaBefore, $this->schemaSnapshot());
        self::assertSame(9, $this->seedCount("book_type", "library-adoption"));
        self::assertSame(12, $this->seedCount("genre", "library-adoption"));
        self::assertSame(0, $this->tableCount(
            $this->tableNames->librarySubjects(),
            "library-adoption"
        ));
        self::assertSame(9, $this->seedCount("book_type", "library-ambiguous"));
        self::assertSame(11, $this->seedCount("genre", "library-ambiguous"));
        self::assertSame(0, $this->tableCount(
            $this->tableNames->librarySubjects(),
            "library-ambiguous"
        ));
        self::assertSame(
            ["local-reading-book", "Mijn Leesboek", "inactive"],
            $this->termState(
                $this->tableNames->libraryBookTypes(),
                "book_type_id",
                "library-adoption",
                "book_type.reading_book"
            )
        );
        self::assertSame(
            ["local-thriller", "Eigen thrillernaam", "inactive"],
            $this->termState(
                $this->tableNames->libraryGenres(),
                "genre_id",
                "library-adoption",
                "genre.thriller"
            )
        );
        self::assertSame(0, $this->tableCount(
            $this->tableNames->libraryActivityEvents()
        ));
        $contextState = $this->database->get_row(
            "SELECT book_type_id, context_version FROM "
            . "`{$this->tableNames->libraryCatalogContexts()}` "
            . "WHERE library_id = 'library-adoption' "
            . "AND work_id = 'existing-context-work'",
            ARRAY_N
        );
        self::assertIsArray($contextState);
        self::assertSame(
            ["local-reading-book", "7"],
            array_map("strval", $contextState)
        );

        $health = (new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1002);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertCount(1, $health->warnings());
        $warning = $health->warnings()[0];
        self::assertSame(
            "classification_seed_adoption_ambiguous",
            $warning->code()
        );
        self::assertSame([
            "library_id" => "library-ambiguous",
            "taxonomy_type" => "genre",
            "seed_key" => "genre.fantasy",
            "candidate_term_ids" => ["local-fantasy"],
        ], $warning->context());
        self::assertSame(
            ["local-fantasy", "Lokale fantasy", "inactive"],
            $this->termState(
                $this->tableNames->libraryGenres(),
                "genre_id",
                "library-ambiguous",
                "genre.local_fantasy"
            )
        );
        $lifecycleState = new WpTransientLifecycleStateStore(300, 60);
        $lifecycleState->clear();
        (new ProductionComposition(
            $this->database,
            $lifecycleState
        ))->lifecycle()->boot();
        self::assertTrue($lifecycleState->isHealthCurrent(1013));
        $lifecycleState->clear();

        $dataAfterFirstRun = $this->classificationDataSnapshot();
        $this->productionMigrator()->migrate();
        self::assertSame(
            $dataAfterFirstRun,
            $this->classificationDataSnapshot()
        );
    }

    public function testFailureLeaves1001AndRetryConvergesIdempotently(): void
    {
        $this->downgradeToVersion1001();
        $this->insertLibrary("library-a");
        $this->insertLibrary("library-b");
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1001", false);
        $realEvolution = $this->classificationSeedEvolution();
        $failingEvolution = new FailOnLibrarySeedEvolution(
            $realEvolution,
            new LibraryId("library-b")
        );
        $failingMigrator = new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            [
                new CoreSchema1001Migration($this->database, $this->tableNames),
                new CoreSchema1002Migration(
                    $this->database,
                    $this->tableNames,
                    new WpdbLibraryRepository(
                        $this->database,
                        $this->tableNames,
                        false
                    ),
                    $failingEvolution
                ),
            ]
        );

        try {
            $failingMigrator->migrate();
            self::fail("Forced seed-bootstrap failure was hidden.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "Forced seed-bootstrap failure",
                $exception->getMessage()
            );
        }

        self::assertSame(1001, $failingMigrator->installedVersion());
        self::assertSame(9, $this->seedCount("book_type", "library-a"));
        self::assertSame(12, $this->seedCount("genre", "library-a"));
        self::assertSame(0, $this->seedCount("book_type", "library-b"));
        self::assertSame(0, $this->seedCount("genre", "library-b"));

        $this->schema1002Migrator()->migrate();

        self::assertSame(1002, $this->schema1002Migrator()->installedVersion());
        self::assertSame(9, $this->seedCount("book_type", "library-a"));
        self::assertSame(12, $this->seedCount("genre", "library-a"));
        self::assertSame(9, $this->seedCount("book_type", "library-b"));
        self::assertSame(12, $this->seedCount("genre", "library-b"));
        $this->productionMigrator()->migrate();
    }

    private function schema1002Migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            [
                new CoreSchema1001Migration($this->database, $this->tableNames),
                new CoreSchema1002Migration($this->database, $this->tableNames),
            ]
        );
    }

    private function downgradeToVersion1001(): void
    {
        foreach (array_reverse($this->tableNames->schema1013()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        delete_option(CoreSchemaMigrator::VERSION_OPTION);
        (new CoreSchemaBaselineInstaller(
            $this->database,
            $this->tableNames
        ))->install();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1000", false);
        (new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            [new CoreSchema1001Migration($this->database, $this->tableNames)]
        ))->migrate();
    }

    private function productionMigrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        );
    }

    private function insertLibrary(string $libraryId): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
    }

    private function insertBookType(
        string $libraryId,
        string $termId,
        string $displayName,
        string $normalizedName,
        string $status,
        ?string $seedKey = null
    ): void {
        $this->database->insert($this->tableNames->libraryBookTypes(), [
            "library_id" => $libraryId,
            "book_type_id" => $termId,
            "display_name" => $displayName,
            "normalized_name" => $normalizedName,
            "term_status" => $status,
            "seed_key" => $seedKey,
        ]);
    }

    private function insertGenre(
        string $libraryId,
        string $termId,
        string $displayName,
        string $normalizedName,
        string $status,
        ?string $seedKey = null
    ): void {
        $this->database->insert($this->tableNames->libraryGenres(), [
            "library_id" => $libraryId,
            "genre_id" => $termId,
            "display_name" => $displayName,
            "normalized_name" => $normalizedName,
            "term_status" => $status,
            "seed_key" => $seedKey,
        ]);
    }

    private function seedCount(string $taxonomy, string $libraryId): int
    {
        $table = $taxonomy === "book_type"
            ? $this->tableNames->libraryBookTypes()
            : $this->tableNames->libraryGenres();
        $seeds = $taxonomy === "book_type"
            ? DefaultClassificationSeeds::bookTypes()
            : DefaultClassificationSeeds::genres();
        $expectedKeys = array_map(
            static fn (ClassificationSeed $seed): string =>
                $seed->key()->value(),
            $seeds
        );
        $actualKeys = $this->database->get_col($this->database->prepare(
            "SELECT seed_key FROM `{$table}` "
            . "WHERE library_id = %s AND seed_key IS NOT NULL",
            $libraryId
        ));

        return count(array_intersect($expectedKeys, $actualKeys));
    }

    private function tableCount(string $table, ?string $libraryId = null): int
    {
        if ($libraryId === null) {
            return (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$table}`"
            );
        }

        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE library_id = %s",
            $libraryId
        ));
    }

    /** @return list<string> */
    private function termState(
        string $table,
        string $idColumn,
        string $libraryId,
        string $seedKey
    ): array {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT `{$idColumn}`, display_name, term_status FROM `{$table}` "
            . "WHERE library_id = %s AND seed_key = %s",
            $libraryId,
            $seedKey
        ), ARRAY_N);

        return array_map("strval", is_array($row) ? $row : []);
    }

    /** @return array<string, string> */
    private function schemaSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames->schema1001() as $table) {
            $row = $this->database->get_row(
                "SHOW CREATE TABLE `{$table}`",
                ARRAY_N
            );

            if (!is_array($row) || !isset($row[1])) {
                throw new RuntimeException("Could not inspect {$table}.");
            }

            $snapshot[$table] = (string) $row[1];
        }

        return $snapshot;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function classificationDataSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            $this->tableNames->libraryBookTypes(),
            $this->tableNames->libraryGenres(),
            $this->tableNames->librarySubjects(),
            $this->tableNames->libraryCatalogContexts(),
            $this->tableNames->libraryCatalogContextGenres(),
            $this->tableNames->libraryCatalogContextSubjects(),
            $this->tableNames->libraryActivityEvents(),
        ] as $table) {
            $snapshot[$table] = $this->database->get_results(
                "SELECT * FROM `{$table}` ORDER BY 1, 2",
                ARRAY_A
            );
        }

        return $snapshot;
    }
}
