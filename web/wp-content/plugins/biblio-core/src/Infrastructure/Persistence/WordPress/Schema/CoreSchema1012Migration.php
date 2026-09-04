<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1012Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1011; }
    public function targetVersion(): int { return 1012; }

    public function assertPrecondition(): void
    {
        $markers = [
            $this->hasColumn($this->tables->items(), "item_version"),
            $this->hasMarker($this->tables->items(), "items_by_library_identity"),
            $this->hasMarker($this->tables->items(), "items_by_library_status_location"),
            $this->hasMarker($this->tables->items(), "items_status_supported"),
        ];
        $count = count(array_filter($markers));
        if ($count === 0) {
            $source = $this->health->inspectForVersion(1011);
            if (!$source->isHealthy()) { throw new CoreSchemaHealthException($source); }
        } elseif ($count === count($markers)) {
            $item = $this->health->inspectSchema1012ItemLifecycle();
            if (!$item->isHealthy()) {
                throw new CoreSchemaMigrationException("Schema 1012 retry found an unknown Item lifecycle state: " . $item->summary());
            }
        } else {
            throw new CoreSchemaMigrationException("Schema 1012 retry found an incomplete Item lifecycle state.");
        }

        $history = $this->health->inspectExistingSchema1012Additions();
        if (!$history->isHealthy()) {
            throw new CoreSchemaMigrationException("Schema 1012 retry found an unknown Item archive history state: " . $history->summary());
        }
    }

    public function migrate(): void
    {
        $items = $this->tables->items();
        if (!$this->hasColumn($items, "item_version")) {
            $this->execute(
                "ALTER TABLE `{$items}` DROP CONSTRAINT items_status_active,"
                    . "ADD item_version BIGINT UNSIGNED NOT NULL DEFAULT 1,"
                    . "ADD UNIQUE KEY items_by_library_identity (library_id,item_id),"
                    . "ADD KEY items_by_library_status_location (library_id,item_status,location_id,item_id),"
                    . "ADD CONSTRAINT items_status_supported CHECK (item_status IN ('active','archived')),"
                    . "ADD CONSTRAINT items_version_positive CHECK (item_version >= 1)",
                "Item lifecycle"
            );
        }

        $history = $this->tables->itemArchivePeriods();
        if (!$this->tableExists($history)) {
            $collation = $this->database->get_charset_collate();
            $this->execute(
                "CREATE TABLE `{$history}` ("
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "archive_version BIGINT UNSIGNED NOT NULL,"
                    . "archive_reason VARCHAR(32) NOT NULL,"
                    . "archived_at DATETIME(6) NOT NULL,"
                    . "restore_version BIGINT UNSIGNED NULL,"
                    . "restored_at DATETIME(6) NULL,"
                    . "open_item_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (CASE WHEN restored_at IS NULL THEN item_id ELSE NULL END) STORED,"
                    . "PRIMARY KEY (library_id,item_id,archive_version),"
                    . "UNIQUE KEY one_open_item_archive_period (library_id,open_item_id),"
                    . "KEY item_archive_periods_by_item_time (library_id,item_id,archived_at,archive_version),"
                    . "CONSTRAINT item_archive_period_item_fk FOREIGN KEY (library_id,item_id) REFERENCES `{$items}` (library_id,item_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT item_archive_version_valid CHECK (archive_version >= 2),"
                    . "CONSTRAINT item_archive_reason_supported CHECK (archive_reason IN ('sold','given_away','donated','lost','damaged_discarded','not_returned')),"
                    . "CONSTRAINT item_archive_restore_pair CHECK ((restore_version IS NULL) = (restored_at IS NULL)),"
                    . "CONSTRAINT item_archive_restore_version CHECK (restore_version IS NULL OR restore_version > archive_version),"
                    . "CONSTRAINT item_archive_restore_time CHECK (restored_at IS NULL OR restored_at >= archived_at)"
                    . ") ENGINE=InnoDB {$collation}",
                "Item archive history"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1012);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s", DB_NAME, $table
        )) === 1;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s", DB_NAME, $table, $column
        )) === 1;
    }

    private function hasMarker(string $table, string $marker): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s", DB_NAME, $table, $marker
        )) > 0 || (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s", DB_NAME, $table, $marker
        )) > 0;
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException("Could not create schema 1012 component {$component}: " . $this->database->last_error);
        }
    }
}
