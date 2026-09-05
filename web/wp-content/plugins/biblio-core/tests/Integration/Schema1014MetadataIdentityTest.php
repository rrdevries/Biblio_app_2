<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1014Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1014MetadataIdentityTest extends PersistenceIntegrationTestCase
{
    public function testSchema1014IsHealthyUniqueAndProvenanceReady(): void
    {
        $health = $this->migrator()->healthForVersion(1014);

        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(
            ["canonical_isbn_13"],
            $this->indexColumns(
                $this->tableNames->editionIdentifierClaims(),
                "PRIMARY"
            )
        );
        self::assertSame(
            ["edition_id"],
            $this->indexColumns(
                $this->tableNames->editionIdentifierClaims(),
                "edition_identifier_claim_one_per_edition"
            )
        );
        self::assertSame(
            ["edition_id", "provider_key", "provider_record_id", "queried_identifier"],
            $this->indexColumns(
                $this->tableNames->editionMetadataProvenance(),
                "edition_metadata_provenance_identity"
            )
        );
        self::assertSame(1, $this->foreignKeyCount(
            $this->tableNames->editionIdentifierClaims()
        ));
        self::assertSame(1, $this->foreignKeyCount(
            $this->tableNames->editionMetadataProvenance()
        ));
    }

    public function testUpgradePreservesRowsBackfillsAliasesAndAllowsNoIsbn(): void
    {
        $this->restoreSchema1013();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-isbn10",
            "work_title" => "Preserved ISBN-10",
        ]);
        $this->database->insert($works, [
            "work_id" => "work-none",
            "work_title" => "Preserved no ISBN",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-isbn10",
            "work_id" => "work-isbn10",
            "isbn_10" => "0306406152",
            "explicitly_no_isbn" => 0,
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-none",
            "work_id" => "work-none",
            "explicitly_no_isbn" => 1,
        ]);

        $this->migrator()->migrate();

        self::assertSame(1014, $this->migrator()->installedVersion());
        self::assertSame(
            "edition-isbn10",
            $this->database->get_var(
                "SELECT edition_id FROM `{$this->tableNames->editionIdentifierClaims()}` "
                    . "WHERE canonical_isbn_13='9780306406157'"
            )
        );
        self::assertSame(
            1,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$editions}` WHERE edition_id='edition-none'"
            )
        );
        self::assertSame(
            0,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->editionMetadataProvenance()}`"
            )
        );
    }

    public function testDatabaseRejectsDuplicateCanonicalClaim(): void
    {
        $this->insertWorkAndEdition("work-a", "edition-a", "9780306406157");
        $this->insertWorkAndEdition("work-b", "edition-b", null);
        $claims = $this->tableNames->editionIdentifierClaims();
        self::assertSame(1, $this->database->insert($claims, [
            "canonical_isbn_13" => "9780306406157",
            "edition_id" => "edition-a",
        ]));

        $this->database->suppress_errors(true);
        $duplicate = $this->database->insert($claims, [
            "canonical_isbn_13" => "9780306406157",
            "edition_id" => "edition-b",
        ]);
        $this->database->suppress_errors(false);

        self::assertFalse($duplicate);
        self::assertStringContainsString("Duplicate entry", $this->database->last_error);
    }

    public function testMultipleExplicitNoIsbnEditionsRemainAllowed(): void
    {
        foreach (["a", "b"] as $suffix) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => "work-none-{$suffix}",
                "work_title" => "No ISBN {$suffix}",
            ]);
            self::assertSame(1, $this->database->insert(
                $this->tableNames->editions(),
                [
                    "edition_id" => "edition-none-{$suffix}",
                    "work_id" => "work-none-{$suffix}",
                    "explicitly_no_isbn" => 1,
                ]
            ));
        }

        self::assertSame(
            2,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->editions()}` "
                    . "WHERE explicitly_no_isbn=1"
            )
        );
        self::assertSame(
            0,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->editionIdentifierClaims()}`"
            )
        );
    }

    public function testConflictingLegacyFixtureFailsBeforeVersionBump(): void
    {
        $this->restoreSchema1013();
        $this->insertWorkAndEdition("work-a", "edition-a", "9780306406157");
        $this->insertWorkAndEdition("work-b", "edition-b", "9780306406157");

        try {
            $this->migrator()->migrate();
            self::fail("Conflicting canonical ISBN rows were accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("ISBN audit blocked", $exception->getMessage());
            self::assertSame(1013, $this->migrator()->installedVersion());
            self::assertFalse($this->tableExists(
                $this->tableNames->editionIdentifierClaims()
            ));
        } finally {
            $this->database->query(
                "DELETE FROM `{$this->tableNames->editions()}` "
                    . "WHERE edition_id IN ('edition-a','edition-b')"
            );
            $this->database->query(
                "DELETE FROM `{$this->tableNames->works()}` "
                    . "WHERE work_id IN ('work-a','work-b')"
            );
            $this->migrator()->migrate();
        }
    }

    public function testKnownPartialAndCompletedMigrationAreRetrySafe(): void
    {
        $this->restoreSchema1013();
        $migration = new CoreSchema1014Migration(
            $this->database,
            $this->tableNames
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $this->database->query(
            "DROP TABLE `{$this->tableNames->editionMetadataProvenance()}`"
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPostcondition();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1014", false);

        self::assertTrue($this->migrator()->health()->isHealthy());
    }

    private function restoreSchema1013(): void
    {
        $this->database->query(
            "DROP TABLE IF EXISTS `{$this->tableNames->editionMetadataProvenance()}`"
        );
        $this->database->query(
            "DROP TABLE IF EXISTS `{$this->tableNames->editionIdentifierClaims()}`"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1013", false);
    }

    private function insertWorkAndEdition(
        string $workId,
        string $editionId,
        ?string $isbn13
    ): void {
        $this->database->insert($this->tableNames->works(), [
            "work_id" => $workId,
            "work_title" => "Title",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => $editionId,
            "work_id" => $workId,
            "isbn_13" => $isbn13,
            "explicitly_no_isbn" => 0,
        ]);
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        return array_map("strval", $this->database->get_col(
            $this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS "
                    . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s "
                    . "ORDER BY SEQ_IN_INDEX",
                DB_NAME,
                $table,
                $index
            )
        ));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(DISTINCT CONSTRAINT_NAME) "
                . "FROM information_schema.KEY_COLUMN_USAGE "
                . "WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s "
                . "AND REFERENCED_TABLE_NAME IS NOT NULL",
            DB_NAME,
            $table
        ));
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function migrator(): CoreSchemaMigrator
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
}
