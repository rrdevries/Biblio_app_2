<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1008Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1007; }
    public function targetVersion(): int { return 1008; }

    public function assertPrecondition(): void
    {
        if ($this->healthChecker->inspectForVersion(1007)->isHealthy()) {
            return;
        }

        $entries = $this->tables->nextReadingEntries();
        $required = ["entry_id", "user_id", "work_id", "position", "created_at"];
        $columns = $this->columns($entries);
        $validTransition = $this->exists($entries)
            && array_diff($required, $columns) === []
            && $this->exactlyOne($columns, "target_type", "preferred_source_type")
            && $this->exactlyOne($columns, "source_id_snapshot", "preferred_source_id_snapshot")
            && $this->exactlyOne(
                $columns,
                "source_library_id_snapshot",
                "preferred_source_library_id_snapshot"
            );

        if (!$validTransition) {
            throw new CoreSchemaMigrationException(
                "Schema 1008 retry found an unknown partial Next Reading state."
            );
        }
    }

    public function migrate(): void
    {
        $entries = $this->tables->nextReadingEntries();

        foreach ([
            $this->tables->nextReadingInsertTrigger(),
            $this->tables->nextReadingUpdateTrigger(),
        ] as $trigger) {
            if ($this->triggerExists($trigger)) {
                $this->execute("DROP TRIGGER `{$trigger}`", "drop old entry trigger");
            }
        }

        foreach ([
            "one_next_reading_work_target",
            "one_next_reading_item_target",
            "one_next_reading_external_target",
        ] as $index) {
            if ($this->indexExists($entries, $index)) {
                $this->execute("ALTER TABLE `{$entries}` DROP INDEX `{$index}`", "drop target uniqueness");
            }
        }

        foreach (["work_target_id", "item_target_id", "external_target_id"] as $column) {
            if ($this->columnExists($entries, $column)) {
                $this->execute("ALTER TABLE `{$entries}` DROP COLUMN `{$column}`", "drop generated target column");
            }
        }

        foreach (["next_reading_target_type", "next_reading_target_shape"] as $check) {
            if ($this->checkExists($entries, $check)) {
                $this->execute("ALTER TABLE `{$entries}` DROP CONSTRAINT `{$check}`", "drop old target check");
            }
        }

        if ($this->columnExists($entries, "target_type")) {
            $this->execute(
                "ALTER TABLE `{$entries}` MODIFY COLUMN target_type VARCHAR(32) NULL",
                "make old target discriminator nullable"
            );
            $this->execute(
                "UPDATE `{$entries}` SET target_type=NULL WHERE target_type='work'",
                "migrate Work targets to entries without preference"
            );
            $this->execute(
                "ALTER TABLE `{$entries}` CHANGE COLUMN target_type preferred_source_type VARCHAR(32) NULL",
                "rename preferred-source discriminator"
            );
        }
        if ($this->columnExists($entries, "source_id_snapshot")) {
            $this->execute(
                "ALTER TABLE `{$entries}` CHANGE COLUMN source_id_snapshot preferred_source_id_snapshot "
                    . "VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL",
                "rename preferred-source snapshot"
            );
        }
        if ($this->columnExists($entries, "source_library_id_snapshot")) {
            $this->execute(
                "ALTER TABLE `{$entries}` CHANGE COLUMN source_library_id_snapshot preferred_source_library_id_snapshot "
                    . "VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL",
                "rename preferred-source Library snapshot"
            );
        }

        $this->addIndex(
            $entries,
            "next_reading_by_user_work_order",
            "user_id,work_id,position,entry_id"
        );
        $this->addIndex(
            $entries,
            "next_reading_by_user_work_item",
            "user_id,work_id,item_id,position,entry_id"
        );
        $this->addIndex(
            $entries,
            "next_reading_by_user_work_external",
            "user_id,work_id,external_loan_id,position,entry_id"
        );

        if (!$this->checkExists($entries, "next_reading_preferred_source_type")) {
            $this->execute(
                "ALTER TABLE `{$entries}` ADD CONSTRAINT next_reading_preferred_source_type CHECK "
                    . "(preferred_source_type IS NULL OR preferred_source_type IN ('library_item','external_loan'))",
                "add preferred-source type check"
            );
        }
        if (!$this->checkExists($entries, "next_reading_preferred_source_shape")) {
            $this->execute(
                "ALTER TABLE `{$entries}` ADD CONSTRAINT next_reading_preferred_source_shape CHECK ("
                    . "(preferred_source_type IS NULL AND preferred_source_id_snapshot IS NULL "
                    . "AND preferred_source_library_id_snapshot IS NULL) OR "
                    . "(preferred_source_type='library_item' AND preferred_source_id_snapshot IS NOT NULL "
                    . "AND preferred_source_library_id_snapshot IS NOT NULL) OR "
                    . "(preferred_source_type='external_loan' AND preferred_source_id_snapshot IS NOT NULL "
                    . "AND preferred_source_library_id_snapshot IS NULL))",
                "add preferred-source shape check"
            );
        }

        if (!$this->exists($this->tables->nextReadingUndo())) {
            $this->execute($this->undoSql(), "create Next Reading Undo table");
        }

        foreach ([
            [$this->tables->nextReadingInsertTrigger(), "INSERT", $entries],
            [$this->tables->nextReadingUpdateTrigger(), "UPDATE", $entries],
            [$this->tables->nextReadingUndoInsertTrigger(), "INSERT", $this->tables->nextReadingUndo()],
            [$this->tables->nextReadingUndoUpdateTrigger(), "UPDATE", $this->tables->nextReadingUndo()],
        ] as [$trigger, $event, $table]) {
            if (!$this->triggerExists($trigger)) {
                $this->execute(
                    $this->triggerSql($event, $trigger, $table),
                    "create preferred-source invariant trigger"
                );
            }
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1008);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function undoSql(): string
    {
        $undo = $this->tables->nextReadingUndo();
        $lists = $this->tables->nextReadingLists();
        $works = $this->tables->works();
        $items = $this->tables->items();
        $loans = $this->tables->externalLoans();
        return "CREATE TABLE `{$undo}` ("
            . "undo_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "entry_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "work_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "preferred_source_type VARCHAR(32) NULL,"
            . "preferred_source_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "preferred_source_library_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "item_id VARCHAR(191) COLLATE utf8mb4_bin NULL,external_loan_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "original_position BIGINT UNSIGNED NOT NULL,"
            . "previous_entry_id VARCHAR(191) COLLATE utf8mb4_bin NULL,next_entry_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "entry_created_at DATETIME(6) NOT NULL,created_at DATETIME(6) NOT NULL,expires_at DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (undo_token_hash),KEY next_reading_undo_by_user_expiry (user_id,expires_at),"
            . "CONSTRAINT next_reading_undo_owner_fk FOREIGN KEY (user_id) REFERENCES `{$lists}` (user_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
            . "CONSTRAINT next_reading_undo_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT next_reading_undo_item_fk FOREIGN KEY (item_id) REFERENCES `{$items}` (item_id) ON UPDATE RESTRICT ON DELETE SET NULL,"
            . "CONSTRAINT next_reading_undo_external_fk FOREIGN KEY (external_loan_id) REFERENCES `{$loans}` (external_loan_id) ON UPDATE RESTRICT ON DELETE SET NULL,"
            . "CONSTRAINT next_reading_undo_token_shape CHECK (CHAR_LENGTH(undo_token_hash)=64),"
            . "CONSTRAINT next_reading_undo_preferred_type CHECK (preferred_source_type IS NULL OR preferred_source_type IN ('library_item','external_loan')),"
            . "CONSTRAINT next_reading_undo_preferred_shape CHECK ((preferred_source_type IS NULL AND preferred_source_id_snapshot IS NULL AND preferred_source_library_id_snapshot IS NULL) OR (preferred_source_type='library_item' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NOT NULL) OR (preferred_source_type='external_loan' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NULL)),"
            . "CONSTRAINT next_reading_undo_position_positive CHECK (original_position>=1),"
            . "CONSTRAINT next_reading_undo_expiry_order CHECK (expires_at>created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function triggerSql(string $event, string $trigger, string $table): string
    {
        return "CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW "
            . "BEGIN IF ((NEW.preferred_source_type IS NULL AND (NEW.item_id IS NOT NULL OR NEW.external_loan_id IS NOT NULL)) "
            . "OR (NEW.preferred_source_type='library_item' AND (NEW.external_loan_id IS NOT NULL OR (NEW.item_id IS NOT NULL AND BINARY NEW.item_id<>BINARY NEW.preferred_source_id_snapshot))) "
            . "OR (NEW.preferred_source_type='external_loan' AND (NEW.item_id IS NOT NULL OR (NEW.external_loan_id IS NOT NULL AND BINARY NEW.external_loan_id<>BINARY NEW.preferred_source_id_snapshot)))) "
            . "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Next Reading preferred source shape'; END IF; END";
    }

    private function addIndex(string $table, string $name, string $columns): void
    {
        if (!$this->indexExists($table, $name)) {
            $this->execute("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})", "add Next Reading index");
        }
    }

    private function execute(string $sql, string $subject): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not {$subject}: " . $this->database->last_error
            );
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

    /** @param list<string> $columns */
    private function exactlyOne(array $columns, string $old, string $new): bool
    {
        return in_array($old, $columns, true) !== in_array($new, $columns, true);
    }

    private function exists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s",
            DB_NAME,
            $table,
            $index
        )) > 0;
    }

    private function checkExists(string $table, string $check): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s AND CONSTRAINT_TYPE='CHECK'",
            DB_NAME,
            $table,
            $check
        )) === 1;
    }

    private function triggerExists(string $trigger): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND TRIGGER_NAME=%s",
            DB_NAME,
            $trigger
        )) === 1;
    }
}
