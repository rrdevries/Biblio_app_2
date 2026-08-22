<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1004Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tableNames);
    }

    public function sourceVersion(): int { return 1003; }
    public function targetVersion(): int { return 1004; }

    public function assertPrecondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1003);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }

        if ($this->tableExists()) {
            $retryHealth = $this->healthChecker->inspectForVersion(1004);

            if (!$retryHealth->isHealthy()) {
                throw new CoreSchemaMigrationException(
                    "Schema 1004 retry found an unknown Private Note table state: "
                    . $retryHealth->summary()
                );
            }
        }
    }

    public function migrate(): void
    {
        if ($this->tableExists()) {
            return;
        }

        $notes = $this->tableNames->privateNotes();
        $works = $this->tableNames->works();
        $rounds = $this->tableNames->readingRounds();
        $sql = "CREATE TABLE `{$notes}` ("
            . "private_note_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "work_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "reading_round_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "note_content TEXT NOT NULL,"
            . "created_at DATETIME(6) NOT NULL,"
            . "updated_at DATETIME(6) NOT NULL,"
            . "note_version BIGINT UNSIGNED NOT NULL,"
            . "PRIMARY KEY (private_note_id),"
            . "KEY private_notes_by_user_work (user_id, work_id, updated_at, private_note_id),"
            . "KEY private_notes_by_user_round (user_id, reading_round_id, updated_at, private_note_id),"
            . "KEY private_notes_by_user_updated (user_id, updated_at, private_note_id),"
            . "CONSTRAINT private_notes_work_fk FOREIGN KEY (work_id) "
            . "REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT private_notes_round_fk FOREIGN KEY (reading_round_id) "
            . "REFERENCES `{$rounds}` (reading_round_id) ON UPDATE RESTRICT ON DELETE SET NULL,"
            . "CONSTRAINT private_notes_version_positive CHECK (note_version >= 1),"
            . "CONSTRAINT private_notes_technical_time CHECK (updated_at >= created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1004 Private Notes table: "
                . $this->database->last_error
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1004);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function tableExists(): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $this->tableNames->privateNotes()
        )) === 1;
    }
}
