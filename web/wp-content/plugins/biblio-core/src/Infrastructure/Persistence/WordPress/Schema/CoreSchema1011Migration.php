<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1011Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1010; }
    public function targetVersion(): int { return 1011; }

    public function assertPrecondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1010);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }

        $locations = $this->healthChecker->inspectExistingSchema1011Additions();
        if (!$locations->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1011 retry found an unknown Location table state: " . $locations->summary()
            );
        }

        $markers = [
            $this->hasColumn($this->tables->items(), "location_id"),
            $this->hasMarker($this->tables->items(), "items_by_library_location"),
            $this->hasMarker($this->tables->items(), "items_location_fk"),
        ];
        if (in_array(true, $markers, true)) {
            $itemHealth = $this->healthChecker->inspectSchema1011ItemLocation();
            if (!$itemHealth->isHealthy()) {
                throw new CoreSchemaMigrationException(
                    "Schema 1011 retry found an unknown Item Location state: " . $itemHealth->summary()
                );
            }
        }
    }

    public function migrate(): void
    {
        $locations = $this->tables->locations();
        $libraries = $this->tables->libraries();
        if (!$this->tableExists($locations)) {
            $collation = $this->database->get_charset_collate();
            $this->execute(
                "CREATE TABLE `{$locations}` ("
                    . "library_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "location_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "display_name VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                    . "PRIMARY KEY (library_id,location_id),"
                    . "KEY locations_by_library_name (library_id,display_name(255),location_id),"
                    . "CONSTRAINT locations_library_fk FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                    . "CONSTRAINT location_name_non_empty CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)"
                    . ") ENGINE=InnoDB {$collation}",
                "Location table"
            );
        }

        $items = $this->tables->items();
        if (!$this->hasColumn($items, "location_id")) {
            $this->execute(
                "ALTER TABLE `{$items}` "
                    . "ADD location_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "ADD KEY items_by_library_location (library_id,location_id,item_id),"
                    . "ADD CONSTRAINT items_location_fk FOREIGN KEY (library_id,location_id) REFERENCES `{$locations}` (library_id,location_id) ON UPDATE RESTRICT ON DELETE RESTRICT",
                "Item Location relation"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1011);
        if (!$health->isHealthy()) { throw new CoreSchemaHealthException($health); }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    private function hasMarker(string $table, string $marker): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s",
            DB_NAME,
            $table,
            $marker
        )) > 0 || (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s",
            DB_NAME,
            $table,
            $marker
        )) > 0;
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1011 component {$component}: " . $this->database->last_error
            );
        }
    }
}
