<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1006Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1006NextReadingTest extends PersistenceIntegrationTestCase
{
    public function testSchema1006HasExactlyTwoHealthyTablesAndAllDatabaseDefenses(): void
    {
        $health = $this->migrator()->healthForVersion(1006);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(4, $this->columnCount($this->tableNames->nextReadingLists()));
        self::assertSame(13, $this->columnCount($this->tableNames->nextReadingEntries()));
        self::assertSame("CASCADE", $this->deleteRule("user_id"));
        self::assertSame("RESTRICT", $this->deleteRule("work_id"));
        self::assertSame("SET NULL", $this->deleteRule("item_id"));
        self::assertSame("SET NULL", $this->deleteRule("external_loan_id"));
        self::assertSame(2, $this->triggerCount());
    }

    public function testTargetShapeDuplicatePositionAndForeignKeysAreRejected(): void
    {
        $this->database->insert($this->tableNames->works(), ["work_id" => "schema-work", "work_title" => "Schema Work"]);
        $this->database->insert($this->tableNames->nextReadingLists(), [
            "user_id" => "schema-user", "list_version" => 1,
            "created_at" => "2026-08-23 10:00:00.000000", "updated_at" => "2026-08-23 10:00:00.000000",
        ]);
        self::assertSame(1, $this->insertWork("entry-1", "schema-work", 1));

        $previous = $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->insertWork("entry-2", "schema-work", 2));
            self::assertFalse($this->insertWork("entry-3", "missing-work", 2));
            self::assertFalse($this->database->insert($this->tableNames->nextReadingEntries(), [
                "entry_id" => "entry-bad-shape", "user_id" => "schema-user", "work_id" => "schema-work",
                "target_type" => "work", "source_id_snapshot" => null, "source_library_id_snapshot" => null,
                "item_id" => "forbidden-item", "external_loan_id" => null, "position" => 2,
                "created_at" => "2026-08-23 10:00:00.000000",
            ]));
            self::assertFalse($this->insertWork("entry-position", "schema-work-2", 1));
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    public function testKnownTriggerRetryConvergesAndUnknownPartialFailsClosed(): void
    {
        $migration = new CoreSchema1006Migration($this->database, $this->tableNames);
        $this->database->query("DROP TRIGGER `{$this->tableNames->nextReadingUpdateTrigger()}`");
        self::assertFalse($this->migrator()->healthForVersion(1006)->isHealthy());
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1005", false);
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        self::assertSame(2, $this->triggerCount());

        $entries = $this->tableNames->nextReadingEntries();
        $this->database->query("DROP TABLE `{$entries}`");
        $this->database->query("CREATE TABLE `{$entries}` (entry_id VARCHAR(191) NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        try {
            (new CoreSchema1006Migration($this->database, $this->tableNames))->assertPrecondition();
            self::fail("Unknown partial schema 1006 was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("unknown partial", $exception->getMessage());
        } finally {
            $this->database->query("DROP TABLE `{$entries}`");
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1005", false);
            $this->migrator()->migrate();
        }
    }

    public function testReal1005UpgradePreservesExistingDataAndDataDriftIsDetected(): void
    {
        $entries = $this->tableNames->nextReadingEntries();
        $lists = $this->tableNames->nextReadingLists();
        $this->database->query("DROP TABLE `{$entries}`");
        $this->database->query("DROP TABLE `{$lists}`");
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "upgrade-sentinel", "work_title" => "Preserved 1005 Work",
        ]);
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1005", false);
        $this->migrator()->migrate();

        self::assertSame(1007, $this->migrator()->installedVersion());
        self::assertSame("Preserved 1005 Work", $this->database->get_var(
            "SELECT work_title FROM `{$this->tableNames->works()}` WHERE work_id='upgrade-sentinel'"
        ));
        self::assertTrue($this->migrator()->healthForVersion(1006)->isHealthy());

        $this->database->insert($lists, [
            "user_id" => "drift-user", "list_version" => 1,
            "created_at" => "2026-08-23 10:00:00.000000", "updated_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%d", "%s", "%s"]);
        $this->database->insert($entries, [
            "entry_id" => "drift-entry", "user_id" => "drift-user", "work_id" => "upgrade-sentinel",
            "target_type" => "work", "source_id_snapshot" => null, "source_library_id_snapshot" => null,
            "item_id" => null, "external_loan_id" => null, "position" => 2,
            "created_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]);
        $health = $this->migrator()->healthForVersion(1006);
        self::assertFalse($health->isHealthy());
        self::assertStringContainsString("not contiguous", $health->summary());
    }

    private function insertWork(string $entry, string $work, int $position): int|false
    {
        return $this->database->insert($this->tableNames->nextReadingEntries(), [
            "entry_id" => $entry, "user_id" => "schema-user", "work_id" => $work,
            "target_type" => "work", "source_id_snapshot" => null,
            "source_library_id_snapshot" => null, "item_id" => null,
            "external_loan_id" => null, "position" => $position,
            "created_at" => "2026-08-23 10:00:00.000000",
        ]);
    }

    private function columnCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        ));
    }

    private function deleteRule(string $column): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=%s AND k.TABLE_NAME=%s AND k.COLUMN_NAME=%s",
            DB_NAME,
            $this->tableNames->nextReadingEntries(),
            $column
        ));
    }

    private function triggerCount(): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s",
            DB_NAME,
            $this->tableNames->nextReadingEntries()
        ));
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations()
        );
    }
}
