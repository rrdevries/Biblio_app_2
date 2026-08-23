<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1006Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1005; }
    public function targetVersion(): int { return 1006; }

    public function assertPrecondition(): void
    {
        $base = $this->healthChecker->inspectForVersion(1005);
        if (!$base->isHealthy()) {
            throw new CoreSchemaHealthException($base);
        }
        $targets = [$this->tables->nextReadingLists(), $this->tables->nextReadingEntries()];
        $present = array_values(array_filter($targets, fn (string $table): bool => $this->exists($table)));
        if ($present === []) {
            return;
        }
        $retry = $this->healthChecker->inspectForVersion(1006);
        $expectedPrefix = array_slice($targets, 0, count($present));
        $allowedMissing = array_map(
            static fn (string $table): string => "Missing required table {$table}",
            array_slice($targets, count($present))
        );
        if ($present === $targets) {
            $allowedMissing = [];
            foreach ([
                $this->tables->nextReadingInsertTrigger(),
                $this->tables->nextReadingUpdateTrigger(),
            ] as $trigger) {
                if (!$this->triggerExists($trigger)) {
                    $allowedMissing[] = "Missing required trigger {$trigger}";
                }
            }
        }
        if ($present !== $expectedPrefix || $retry->errors() !== $allowedMissing) {
            throw new CoreSchemaMigrationException(
                "Schema 1006 retry found an unknown partial Next Reading state: " . $retry->summary()
            );
        }
    }

    public function migrate(): void
    {
        if (!$this->exists($this->tables->nextReadingLists())) {
            $this->query($this->listSql(), "list-state");
        }
        if (!$this->exists($this->tables->nextReadingEntries())) {
            $this->query($this->entrySql(), "entries");
        }
        if (!$this->triggerExists($this->tables->nextReadingInsertTrigger())) {
            $this->query($this->triggerSql("INSERT", $this->tables->nextReadingInsertTrigger()), "insert invariant trigger");
        }
        if (!$this->triggerExists($this->tables->nextReadingUpdateTrigger())) {
            $this->query($this->triggerSql("UPDATE", $this->tables->nextReadingUpdateTrigger()), "update invariant trigger");
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1006);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function listSql(): string
    {
        $table = $this->tables->nextReadingLists();
        return "CREATE TABLE `{$table}` ("
            . "user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "list_version BIGINT UNSIGNED NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (user_id),"
            . "CONSTRAINT next_reading_list_user_nonempty CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),"
            . "CONSTRAINT next_reading_list_version_positive CHECK (list_version >= 1),"
            . "CONSTRAINT next_reading_list_technical_time CHECK (updated_at >= created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function entrySql(): string
    {
        $table = $this->tables->nextReadingEntries();
        $lists = $this->tables->nextReadingLists();
        $works = $this->tables->works();
        $items = $this->tables->items();
        $loans = $this->tables->externalLoans();
        return "CREATE TABLE `{$table}` ("
            . "entry_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "work_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,target_type VARCHAR(32) NOT NULL,"
            . "source_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "source_library_id_snapshot VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "item_id VARCHAR(191) COLLATE utf8mb4_bin NULL,external_loan_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "position BIGINT UNSIGNED NOT NULL,created_at DATETIME(6) NOT NULL,"
            . "work_target_id VARCHAR(191) COLLATE utf8mb4_bin AS (CASE WHEN target_type='work' THEN work_id ELSE NULL END) STORED,"
            . "item_target_id VARCHAR(191) COLLATE utf8mb4_bin AS (CASE WHEN target_type='library_item' THEN source_id_snapshot ELSE NULL END) STORED,"
            . "external_target_id VARCHAR(191) COLLATE utf8mb4_bin AS (CASE WHEN target_type='external_loan' THEN source_id_snapshot ELSE NULL END) STORED,"
            . "PRIMARY KEY (entry_id),UNIQUE KEY next_reading_user_position (user_id,position),"
            . "UNIQUE KEY one_next_reading_work_target (user_id,work_target_id),"
            . "UNIQUE KEY one_next_reading_item_target (user_id,item_target_id),"
            . "UNIQUE KEY one_next_reading_external_target (user_id,external_target_id),"
            . "KEY next_reading_by_user_order (user_id,position,entry_id),"
            . "CONSTRAINT next_reading_owner_fk FOREIGN KEY (user_id) REFERENCES `{$lists}` (user_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
            . "CONSTRAINT next_reading_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT next_reading_item_fk FOREIGN KEY (item_id) REFERENCES `{$items}` (item_id) ON UPDATE RESTRICT ON DELETE SET NULL,"
            . "CONSTRAINT next_reading_external_fk FOREIGN KEY (external_loan_id) REFERENCES `{$loans}` (external_loan_id) ON UPDATE RESTRICT ON DELETE SET NULL,"
            . "CONSTRAINT next_reading_target_type CHECK (target_type IN ('work','library_item','external_loan')),"
            . "CONSTRAINT next_reading_target_shape CHECK ("
            . "(target_type='work' AND source_id_snapshot IS NULL AND source_library_id_snapshot IS NULL) OR "
            . "(target_type='library_item' AND source_id_snapshot IS NOT NULL AND source_library_id_snapshot IS NOT NULL) OR "
            . "(target_type='external_loan' AND source_id_snapshot IS NOT NULL AND source_library_id_snapshot IS NULL)),"
            . "CONSTRAINT next_reading_position_positive CHECK (position >= 1)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function triggerSql(string $event, string $trigger): string
    {
        $table = $this->tables->nextReadingEntries();
        return "CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW "
            . "BEGIN IF ((NEW.target_type='work' AND (NEW.item_id IS NOT NULL OR NEW.external_loan_id IS NOT NULL)) "
            . "OR (NEW.target_type='library_item' AND (NEW.external_loan_id IS NOT NULL OR (NEW.item_id IS NOT NULL AND BINARY NEW.item_id<>BINARY NEW.source_id_snapshot))) "
            . "OR (NEW.target_type='external_loan' AND (NEW.item_id IS NOT NULL OR (NEW.external_loan_id IS NOT NULL AND BINARY NEW.external_loan_id<>BINARY NEW.source_id_snapshot)))) "
            . "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Next Reading live source shape'; END IF; END";
    }

    private function query(string $sql, string $subject): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1006 Next Reading {$subject} table: " . $this->database->last_error
            );
        }
    }

    private function exists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
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
