<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1009Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1009AuthorSeriesTest extends PersistenceIntegrationTestCase
{
    public function testSchema1009IsHealthyAndHasRequiredRelations(): void
    {
        $health = $this->migrator()->healthForVersion(1009);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(2, $this->foreignKeyCount($this->tableNames->workContributors()));
        self::assertSame(2, $this->foreignKeyCount($this->tableNames->workSeries()));
        self::assertSame(["work_id", "author_id"], $this->indexColumns($this->tableNames->workContributors(), "PRIMARY"));
        self::assertSame(["author_id", "work_id"], $this->indexColumns($this->tableNames->workContributors(), "work_contributors_by_author"));
        self::assertSame(["series_id", "series_position", "work_id"], $this->indexColumns($this->tableNames->workSeries(), "work_series_by_series_order"));
    }

    public function testUpgradeFrom1008PreservesExistingDataAndHasNoLegacyMetadataToMigrate(): void
    {
        $this->dropFoundationTables();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1008", false);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "preserved-work",
            "work_title" => "Preserved title",
        ]);

        $this->migrator()->migrate();

        self::assertSame(1010, $this->migrator()->installedVersion());
        self::assertSame("Preserved title", $this->database->get_var(
            "SELECT work_title FROM `{$this->tableNames->works()}` WHERE work_id='preserved-work'"
        ));
        self::assertSame([], array_intersect(
            ["author_id", "author_name", "series_id", "series_name"],
            $this->columns($this->tableNames->works())
        ));
        self::assertTrue($this->migrator()->healthForVersion(1009)->isHealthy());
    }

    public function testKnownPartialMigrationIsRetryable(): void
    {
        $this->dropFoundationTables();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1008", false);
        $migration = new CoreSchema1009Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->assertPostcondition();

        update_option(CoreSchemaMigrator::VERSION_OPTION, "1009", false);
        self::assertTrue($this->migrator()->healthForVersion(1009)->isHealthy());
    }

    public function testUnknownPartialStateFailsBeforeVersionBumpAndCanBeRecoveredExplicitly(): void
    {
        $authors = $this->tableNames->authors();
        $this->database->query("ALTER TABLE `{$authors}` DROP INDEX authors_by_display_name");
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1008", false);

        try {
            $this->migrator()->migrate();
            self::fail("Unknown partial schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("failed before the version bump", $exception->getMessage());
            self::assertStringContainsString("unknown Author/Series state", $exception->getMessage());
            self::assertSame(1008, $this->migrator()->installedVersion());
        }

        $this->dropFoundationTables();
        $this->migrator()->migrate();
        self::assertSame(1010, $this->migrator()->installedVersion());
        self::assertTrue($this->migrator()->healthForVersion(1009)->isHealthy());
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations()
        );
    }

    private function dropFoundationTables(): void
    {
        foreach (array_reverse($this->tableNames->schema1009Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )));
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX",
            DB_NAME,
            $table,
            $index
        )));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_TYPE='FOREIGN KEY'",
            DB_NAME,
            $table
        ));
    }
}
