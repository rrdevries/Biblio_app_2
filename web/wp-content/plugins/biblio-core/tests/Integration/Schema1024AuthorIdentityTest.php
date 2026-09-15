<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{
    CoreSchema1024Migration,
    CoreSchemaMigrationException,
    CoreSchemaMigrationRegistry,
    CoreSchemaMigrator
};

final class Schema1024AuthorIdentityTest extends PersistenceIntegrationTestCase
{
    public function testSchema1024IsHealthyWithClosedIdentityFoundation(): void
    {
        $health = $this->migrator()->healthForVersion(1024);

        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(1026, $this->migrator()->installedVersion());
        self::assertSame(
            ["display_name", "identity_status", "display_name_status", "author_version"],
            $this->columnsAfter($this->tableNames->authors(), "author_id")
        );
        self::assertSame(
            ["author_id", "credit_id"],
            $this->indexColumns(
                $this->tableNames->authorContributorCredits(),
                "author_credit_by_author"
            )
        );
    }

    public function testUpgradePreservesAuthorsContributorsAndProviderClaims(): void
    {
        $this->setHistoricalSchemaVersion(1023);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "preserved-work",
            "work_title" => "Preserved Work",
            "work_title_status" => "provisional",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "preserved-edition",
            "work_id" => "preserved-work",
            "edition_title" => "Preserved Edition",
            "isbn_10" => null,
            "isbn_13" => null,
            "explicitly_no_isbn" => 1,
        ]);
        $this->database->insert($this->tableNames->authors(), [
            "author_id" => "preserved-author",
            "display_name" => "Peter King",
        ]);
        $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "preserved-work",
            "author_id" => "preserved-author",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]);
        foreach ([
            ["work", "work", "preserved-work", null],
            ["edition", "work", "preserved-work", null],
            ["edition", "edition", null, "preserved-edition"],
        ] as $offset => [$sourceType, $targetType, $workId, $editionId]) {
            $this->database->insert(
                $this->tableNames->bibliographicProviderIdentities(),
                [
                    "provider_key" => "open_library",
                    "source_entity_type" => $sourceType,
                    "provider_record_id" => "/{$sourceType}/preserved-{$offset}",
                    "target_type" => $targetType,
                    "work_id" => $workId,
                    "edition_id" => $editionId,
                ]
            );
        }

        $this->migrator()->migrate();

        self::assertSame(1026, $this->migrator()->installedVersion());
        $author = $this->database->get_row(
            "SELECT * FROM `{$this->tableNames->authors()}` "
                . "WHERE author_id='preserved-author'"
        );
        self::assertSame("Peter King", (string) $author->display_name);
        self::assertSame("provisional", (string) $author->identity_status);
        self::assertSame("observed", (string) $author->display_name_status);
        self::assertSame(1, (int) $author->author_version);
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(
            3,
            $this->countRows($this->tableNames->bibliographicProviderIdentities())
        );
    }

    public function testEmptyUpgradeAndCompletedComponentRetryAreSafe(): void
    {
        $this->setHistoricalSchemaVersion(1023);
        $migration = new CoreSchema1024Migration(
            $this->database,
            $this->tableNames
        );

        $migration->assertPrecondition();
        $migration->migrate();
        $this->database->query(
            "DROP TABLE `{$this->tableNames->authorCreditEvidence()}`"
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPostcondition();

        update_option(CoreSchemaMigrator::VERSION_OPTION, "1024", false);
        self::assertTrue($this->migrator()->healthForVersion(1024)->isHealthy());
    }

    public function testUnknownPartialAuthorShapeFailsBeforeFurtherMutation(): void
    {
        $this->setHistoricalSchemaVersion(1023);
        $authors = $this->tableNames->authors();
        $this->database->query(
            "ALTER TABLE `{$authors}` ADD identity_status VARCHAR(16) "
                . "CHARACTER SET ascii COLLATE ascii_bin NULL"
        );

        try {
            (new CoreSchema1024Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Partial Author schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "partial altered table",
                $exception->getMessage()
            );
            self::assertSame(1023, $this->migrator()->installedVersion());
            self::assertFalse($this->tableExistsForTest(
                $this->tableNames->authorContributorCredits()
            ));
        } finally {
            $this->database->query(
                "ALTER TABLE `{$authors}` DROP COLUMN identity_status"
            );
            $this->migrator()->migrate();
        }
    }

    public function testDriftedExact1023TablesFailBeforeAny1024Mutation(): void
    {
        $this->setHistoricalSchemaVersion(1023);
        $authors = $this->tableNames->authors();
        $providerIdentities = $this->tableNames
            ->bibliographicProviderIdentities();
        $migration = new CoreSchema1024Migration(
            $this->database,
            $this->tableNames
        );

        $this->database->query(
            "ALTER TABLE `{$authors}` DROP INDEX authors_by_display_name"
        );
        try {
            $migration->assertPrecondition();
            self::fail("Drifted schema-1023 Author table was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown altered table state",
                $exception->getMessage()
            );
            self::assertNotContains("identity_status", $this->columns($authors));
        } finally {
            $this->database->query(
                "ALTER TABLE `{$authors}` "
                    . "ADD INDEX authors_by_display_name (display_name,author_id)"
            );
        }

        $this->database->query(
            "ALTER TABLE `{$providerIdentities}` "
                . "ADD INDEX provider_work_fk_only (work_id),"
                . "DROP INDEX bibliographic_provider_identity_by_work"
        );
        try {
            $migration->assertPrecondition();
            self::fail("Drifted schema-1023 provider table was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown altered table state",
                $exception->getMessage()
            );
            self::assertNotContains(
                "identity_status",
                $this->columns($authors)
            );
            self::assertNotContains(
                "author_id",
                $this->columns($providerIdentities)
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$providerIdentities}` "
                    . "ADD INDEX bibliographic_provider_identity_by_work "
                    . "(work_id,provider_key,source_entity_type,provider_record_id),"
                    . "DROP INDEX provider_work_fk_only"
            );
            $this->migrator()->migrate();
        }
    }

    public function testLegacyCrossTypeClaimStopsMatrixClosureWithoutRepair(): void
    {
        $this->setHistoricalSchemaVersion(1023);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "matrix-work",
            "work_title" => "Matrix Work",
            "work_title_status" => "provisional",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "matrix-edition",
            "work_id" => "matrix-work",
            "edition_title" => "Matrix Edition",
            "isbn_10" => null,
            "isbn_13" => null,
            "explicitly_no_isbn" => 1,
        ]);
        $this->database->insert(
            $this->tableNames->bibliographicProviderIdentities(),
            [
                "provider_key" => "open_library",
                "source_entity_type" => "work",
                "provider_record_id" => "/works/invalid-target",
                "target_type" => "edition",
                "work_id" => null,
                "edition_id" => "matrix-edition",
            ]
        );

        try {
            (new CoreSchema1024Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Legacy cross-type provider claim was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "outside the canonical matrix",
                $exception->getMessage()
            );
            self::assertSame(1, $this->countRows(
                $this->tableNames->bibliographicProviderIdentities()
            ));
        } finally {
            $this->database->query(
                "DELETE FROM `{$this->tableNames->bibliographicProviderIdentities()}`"
            );
            $this->migrator()->migrate();
        }
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

    /** @return list<string> */
    private function columnsAfter(string $table, string $column): array
    {
        $columns = $this->columns($table);
        $offset = array_search($column, $columns, true);
        return array_slice($columns, (int) $offset + 1);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col(
            $this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
                    . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s "
                    . "ORDER BY ORDINAL_POSITION",
                DB_NAME,
                $table
            )
        ));
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

    private function tableExistsForTest(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
