<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1019Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1018; }
    public function targetVersion(): int { return 1019; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1018);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $additions = $this->health->inspectExistingSchema1019Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1019 retry found an unknown Personal Reading Truth state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $works = $this->tables->works();
        $locks = $this->tables->personalWorkReadingLocks();
        $truths = $this->tables->personalReadingTruths();

        if (!$this->tableExists($locks)) {
            $this->execute(
                "CREATE TABLE `{$locks}` ("
                    . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (user_id,work_id),"
                    . "KEY personal_work_reading_locks_by_work (work_id,user_id),"
                    . "CONSTRAINT personal_work_reading_lock_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT personal_work_reading_lock_user_valid CHECK (CHAR_LENGTH(TRIM(user_id)) > 0)"
                    . ") ENGINE=InnoDB {$collation}",
                "personal Work reading locks"
            );
        }

        if (!$this->tableExists($truths)) {
            $this->execute(
                "CREATE TABLE `{$truths}` ("
                    . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "truth_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "truth_version BIGINT UNSIGNED NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (user_id,work_id),"
                    . "KEY personal_reading_truths_by_work (work_id,user_id),"
                    . "CONSTRAINT personal_reading_truth_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT personal_reading_truth_state_valid CHECK (truth_state IN ('read_known_date_unknown','explicit_not_read','unknown')),"
                    . "CONSTRAINT personal_reading_truth_version_valid CHECK (truth_version >= 1),"
                    . "CONSTRAINT personal_reading_truth_user_valid CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),"
                    . "CONSTRAINT personal_reading_truth_times_valid CHECK (updated_at >= created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Personal Reading Truth"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1019);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
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

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1019 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
