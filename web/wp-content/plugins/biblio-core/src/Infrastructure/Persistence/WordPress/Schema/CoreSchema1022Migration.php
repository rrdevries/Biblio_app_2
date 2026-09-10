<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1022Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1021; }
    public function targetVersion(): int { return 1022; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1021);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $additions = $this->health->inspectExistingSchema1022Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1022 retry found an unknown Wishlist state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        $collation = $this->database->get_charset_collate();
        $works = $this->tables->works();
        $editions = $this->tables->editions();
        $states = $this->tables->wishlistWorkStates();
        $entries = $this->tables->wishlistEntries();
        $history = $this->tables->wishlistEntryHistory();

        if (!$this->tableExists($states)) {
            $this->execute(
                "CREATE TABLE `{$states}` ("
                    . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "PRIMARY KEY (user_id,work_id),"
                    . "UNIQUE KEY wishlist_work_state_mode (user_id,work_id,target_type),"
                    . "KEY wishlist_work_states_by_work (work_id,user_id),"
                    . "CONSTRAINT wishlist_work_state_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT wishlist_work_state_target_valid CHECK (target_type IN ('work_only','edition_specific')),"
                    . "CONSTRAINT wishlist_work_state_user_valid CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),"
                    . "CONSTRAINT wishlist_work_state_times_valid CHECK (updated_at >= created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Wishlist Work states"
            );
        }

        if (!$this->tableExists($entries)) {
            $this->execute(
                "CREATE TABLE `{$entries}` ("
                    . "wishlist_entry_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "work_only_work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin AS (CASE WHEN target_type='work_only' THEN work_id ELSE NULL END) STORED,"
                    . "PRIMARY KEY (wishlist_entry_id),"
                    . "UNIQUE KEY wishlist_work_only_unique (user_id,work_only_work_id),"
                    . "UNIQUE KEY wishlist_edition_unique (user_id,edition_id),"
                    . "KEY wishlist_entries_by_owner_created (user_id,created_at,wishlist_entry_id),"
                    . "KEY wishlist_entries_by_work (user_id,work_id,target_type),"
                    . "KEY wishlist_entries_by_edition (edition_id,user_id),"
                    . "CONSTRAINT wishlist_entry_state_fk FOREIGN KEY (user_id,work_id,target_type) REFERENCES `{$states}` (user_id,work_id,target_type) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT wishlist_entry_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT wishlist_entry_target_shape CHECK ((target_type='work_only' AND edition_id IS NULL) OR (target_type='edition_specific' AND edition_id IS NOT NULL)),"
                    . "CONSTRAINT wishlist_entry_user_valid CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),"
                    . "CONSTRAINT wishlist_entry_times_valid CHECK (updated_at >= created_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Wishlist Entries"
            );
        }

        if (!$this->tableExists($history)) {
            $this->execute(
                "CREATE TABLE `{$history}` ("
                    . "wishlist_entry_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "target_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "edition_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "created_at DATETIME(6) NOT NULL,"
                    . "updated_at DATETIME(6) NOT NULL,"
                    . "removed_at DATETIME(6) NOT NULL,"
                    . "removal_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                    . "PRIMARY KEY (wishlist_entry_id),"
                    . "KEY wishlist_history_by_owner_removed (user_id,removed_at,wishlist_entry_id),"
                    . "KEY wishlist_history_by_work (user_id,work_id),"
                    . "KEY wishlist_history_by_edition (edition_id,user_id),"
                    . "CONSTRAINT wishlist_history_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT wishlist_history_edition_fk FOREIGN KEY (edition_id) REFERENCES `{$editions}` (edition_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT wishlist_history_target_shape CHECK ((target_type='work_only' AND edition_id IS NULL) OR (target_type='edition_specific' AND edition_id IS NOT NULL)),"
                    . "CONSTRAINT wishlist_history_reason_valid CHECK (removal_reason IN ('fulfilled','removed','read_and_removed')),"
                    . "CONSTRAINT wishlist_history_user_valid CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),"
                    . "CONSTRAINT wishlist_history_times_valid CHECK (updated_at >= created_at AND removed_at >= updated_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Wishlist Entry history"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1022);
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
                "Could not create schema 1022 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
