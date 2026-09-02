<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1008NextReadingContractCorrectionTest extends PersistenceIntegrationTestCase
{
    public function testSchema1008HasHealthyEntryAndUndoContracts(): void
    {
        $health = $this->migrator()->healthForVersion(1008);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(4, $this->columnCount($this->tableNames->nextReadingLists()));
        self::assertSame(10, $this->columnCount($this->tableNames->nextReadingEntries()));
        self::assertSame(15, $this->columnCount($this->tableNames->nextReadingUndo()));
        self::assertSame("CASCADE", $this->deleteRule($this->tableNames->nextReadingEntries(), "user_id"));
        self::assertSame("RESTRICT", $this->deleteRule($this->tableNames->nextReadingEntries(), "work_id"));
        self::assertSame("SET NULL", $this->deleteRule($this->tableNames->nextReadingEntries(), "item_id"));
        self::assertSame("SET NULL", $this->deleteRule($this->tableNames->nextReadingEntries(), "external_loan_id"));
        self::assertSame(2, $this->triggerCount($this->tableNames->nextReadingEntries()));
        self::assertSame(2, $this->triggerCount($this->tableNames->nextReadingUndo()));
    }

    public function testEntryIdentityAllowsDuplicatesButRejectsBadPreferenceAndPosition(): void
    {
        $this->seedList();
        self::assertSame(1, $this->insertEntry("entry-1", 1));
        self::assertSame(1, $this->insertEntry("entry-2", 2));

        $previous = $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->insertEntry("entry-position", 2));
            self::assertFalse($this->database->insert($this->tableNames->nextReadingEntries(), [
                "entry_id" => "entry-bad-preference",
                "user_id" => "schema-user",
                "work_id" => "schema-work",
                "preferred_source_type" => null,
                "preferred_source_id_snapshot" => null,
                "preferred_source_library_id_snapshot" => null,
                "item_id" => "schema-item",
                "external_loan_id" => null,
                "position" => 3,
                "created_at" => "2026-08-23 10:00:00.000000",
            ]));
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    public function testSourceDeletionNullsOnlyLiveReferenceAndPreservesSnapshot(): void
    {
        $this->seedList(true);
        self::assertSame(1, $this->database->insert($this->tableNames->nextReadingEntries(), [
            "entry_id" => "entry-item",
            "user_id" => "schema-user",
            "work_id" => "schema-work",
            "preferred_source_type" => "library_item",
            "preferred_source_id_snapshot" => "schema-item",
            "preferred_source_library_id_snapshot" => "schema-library",
            "item_id" => "schema-item",
            "external_loan_id" => null,
            "position" => 1,
            "created_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]));

        self::assertSame(1, $this->database->delete($this->tableNames->items(), ["item_id" => "schema-item"]));
        $row = $this->database->get_row(
            "SELECT preferred_source_id_snapshot,item_id,position FROM `{$this->tableNames->nextReadingEntries()}` WHERE entry_id='entry-item'",
            ARRAY_A
        );
        self::assertSame("schema-item", $row["preferred_source_id_snapshot"]);
        self::assertNull($row["item_id"]);
        self::assertSame("1", $row["position"]);
    }

    public function testReal1007TransitionPreservesExistingEntryIdentityAndOrder(): void
    {
        $entries = $this->tableNames->nextReadingEntries();
        $this->database->query("DROP TRIGGER `{$this->tableNames->nextReadingInsertTrigger()}`");
        $this->database->query("DROP TRIGGER `{$this->tableNames->nextReadingUpdateTrigger()}`");
        $this->database->query("DROP TABLE `{$this->tableNames->nextReadingUndo()}`");
        $this->database->query("ALTER TABLE `{$entries}` DROP CONSTRAINT next_reading_preferred_source_type");
        $this->database->query("ALTER TABLE `{$entries}` DROP CONSTRAINT next_reading_preferred_source_shape");
        $this->database->query("ALTER TABLE `{$entries}` CHANGE preferred_source_type target_type VARCHAR(32) NULL");
        $this->database->query("ALTER TABLE `{$entries}` CHANGE preferred_source_id_snapshot source_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL");
        $this->database->query("ALTER TABLE `{$entries}` CHANGE preferred_source_library_id_snapshot source_library_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL");
        $this->seedList(true);
        self::assertSame(1, $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => "schema-loan",
            "user_id" => "schema-user",
            "work_id" => "schema-work",
            "loan_status" => "active",
            "borrowed_at" => "2026-08-23 09:00:00.000000",
            "due_at" => null,
        ], ["%s", "%s", "%s", "%s", "%s", "%s"]));
        self::assertSame(1, $this->database->update(
            $this->tableNames->nextReadingLists(),
            ["list_version" => 9],
            ["user_id" => "schema-user"],
            ["%d"],
            ["%s"]
        ));
        foreach ([
            ["entry-work-first", "work", null, null, null, null, 1, "2026-08-23 10:00:00.000000"],
            ["entry-item", "library_item", "schema-item", "schema-library", "schema-item", null, 2, "2026-08-23 10:01:00.000000"],
            ["entry-loan", "external_loan", "schema-loan", null, null, "schema-loan", 3, "2026-08-23 10:02:00.000000"],
            ["entry-work-second", "work", null, null, null, null, 4, "2026-08-23 10:03:00.000000"],
        ] as [$entryId, $targetType, $sourceId, $libraryId, $itemId, $loanId, $position, $createdAt]) {
            self::assertSame(1, $this->database->insert($entries, [
                "entry_id" => $entryId,
                "user_id" => "schema-user",
                "work_id" => "schema-work",
                "target_type" => $targetType,
                "source_id_snapshot" => $sourceId,
                "source_library_id_snapshot" => $libraryId,
                "item_id" => $itemId,
                "external_loan_id" => $loanId,
                "position" => $position,
                "created_at" => $createdAt,
            ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]));
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1007", false);

        $this->migrator()->migrate();

        self::assertSame(1008, $this->migrator()->installedVersion());
        $rows = $this->database->get_results(
            "SELECT entry_id,work_id,preferred_source_type,preferred_source_id_snapshot,"
            . "preferred_source_library_id_snapshot,item_id,external_loan_id,position,created_at "
            . "FROM `{$entries}` WHERE user_id='schema-user' ORDER BY position ASC",
            ARRAY_A
        );
        self::assertSame(
            ["entry-work-first", "entry-item", "entry-loan", "entry-work-second"],
            array_column($rows, "entry_id")
        );
        self::assertSame(["schema-work", "schema-work", "schema-work", "schema-work"], array_column($rows, "work_id"));
        self::assertSame([null, "library_item", "external_loan", null], array_column($rows, "preferred_source_type"));
        self::assertSame([null, "schema-item", "schema-loan", null], array_column($rows, "preferred_source_id_snapshot"));
        self::assertSame([null, "schema-library", null, null], array_column($rows, "preferred_source_library_id_snapshot"));
        self::assertSame([null, "schema-item", null, null], array_column($rows, "item_id"));
        self::assertSame([null, null, "schema-loan", null], array_column($rows, "external_loan_id"));
        self::assertSame(["1", "2", "3", "4"], array_column($rows, "position"));
        self::assertSame([
            "2026-08-23 10:00:00.000000",
            "2026-08-23 10:01:00.000000",
            "2026-08-23 10:02:00.000000",
            "2026-08-23 10:03:00.000000",
        ], array_column($rows, "created_at"));
        self::assertSame("9", $this->database->get_var(
            "SELECT list_version FROM `{$this->tableNames->nextReadingLists()}` WHERE user_id='schema-user'"
        ));
        self::assertTrue($this->migrator()->healthForVersion(1008)->isHealthy());

        $this->migrator()->migrate();
        self::assertSame($rows, $this->database->get_results(
            "SELECT entry_id,work_id,preferred_source_type,preferred_source_id_snapshot,"
            . "preferred_source_library_id_snapshot,item_id,external_loan_id,position,created_at "
            . "FROM `{$entries}` WHERE user_id='schema-user' ORDER BY position ASC",
            ARRAY_A
        ));
        self::assertSame("9", $this->database->get_var(
            "SELECT list_version FROM `{$this->tableNames->nextReadingLists()}` WHERE user_id='schema-user'"
        ));
    }

    private function seedList(bool $withItem = false): void
    {
        $this->database->insert($this->tableNames->works(), ["work_id" => "schema-work", "work_title" => "Schema Work"]);
        $this->database->insert($this->tableNames->nextReadingLists(), [
            "user_id" => "schema-user",
            "list_version" => 1,
            "created_at" => "2026-08-23 10:00:00.000000",
            "updated_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%d", "%s", "%s"]);
        if (!$withItem) {
            return;
        }
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "schema-library",
            "library_name" => "Schema Library",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->editions(), ["edition_id" => "schema-edition", "work_id" => "schema-work"]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "schema-item",
            "library_id" => "schema-library",
            "edition_id" => "schema-edition",
            "item_status" => "active",
        ]);
    }

    private function insertEntry(string $entry, int $position): int|false
    {
        return $this->database->insert($this->tableNames->nextReadingEntries(), [
            "entry_id" => $entry,
            "user_id" => "schema-user",
            "work_id" => "schema-work",
            "preferred_source_type" => null,
            "preferred_source_id_snapshot" => null,
            "preferred_source_library_id_snapshot" => null,
            "item_id" => null,
            "external_loan_id" => null,
            "position" => $position,
            "created_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]);
    }

    private function columnCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        ));
    }

    private function deleteRule(string $table, string $column): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=%s AND k.TABLE_NAME=%s AND k.COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        ));
    }

    private function triggerCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s",
            DB_NAME,
            $table
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
