<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1010Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1009; }
    public function targetVersion(): int { return 1010; }

    public function assertPrecondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1009);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }

        $this->assertKnownComponentState(
            $this->tables->editions(),
            ["isbn_10", "isbn_13", "explicitly_no_isbn"],
            [
                "editions_by_isbn10",
                "editions_by_isbn13",
                "edition_isbn_flag_valid",
                "edition_isbn_state_valid",
                "edition_isbn10_shape_valid",
                "edition_isbn13_shape_valid",
            ],
            $this->healthChecker->inspectSchema1010EditionMetadata()
        );
        $this->assertKnownComponentState(
            $this->tables->items(),
            ["inventory_number"],
            [
                "items_by_library_inventory_number",
                "items_inventory_number_non_empty",
            ],
            $this->healthChecker->inspectSchema1010ItemMetadata()
        );

        $additions = $this->healthChecker->inspectExistingSchema1010Additions();
        if (!$additions->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1010 retry found an unknown search metadata table state: "
                    . $additions->summary()
            );
        }
    }

    public function migrate(): void
    {
        if (!$this->hasColumn($this->tables->editions(), "isbn_10")) {
            $this->execute(
                "ALTER TABLE `{$this->tables->editions()}` "
                    . "ADD isbn_10 VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "ADD isbn_13 VARCHAR(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "ADD explicitly_no_isbn TINYINT UNSIGNED NOT NULL DEFAULT 0,"
                    . "ADD KEY editions_by_isbn10 (isbn_10,edition_id),"
                    . "ADD KEY editions_by_isbn13 (isbn_13,edition_id),"
                    . "ADD CONSTRAINT edition_isbn_flag_valid CHECK (explicitly_no_isbn IN (0,1)),"
                    . "ADD CONSTRAINT edition_isbn_state_valid CHECK (explicitly_no_isbn = 0 OR (isbn_10 IS NULL AND isbn_13 IS NULL)),"
                    . "ADD CONSTRAINT edition_isbn10_shape_valid CHECK (isbn_10 IS NULL OR isbn_10 REGEXP '^[0-9]{9}[0-9X]$'),"
                    . "ADD CONSTRAINT edition_isbn13_shape_valid CHECK (isbn_13 IS NULL OR isbn_13 REGEXP '^[0-9]{13}$')",
                "Edition ISBN metadata"
            );
        }

        if (!$this->hasColumn($this->tables->items(), "inventory_number")) {
            $this->execute(
                "ALTER TABLE `{$this->tables->items()}` "
                    . "ADD inventory_number VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,"
                    . "ADD UNIQUE KEY items_by_library_inventory_number (library_id,inventory_number),"
                    . "ADD CONSTRAINT items_inventory_number_non_empty CHECK (inventory_number IS NULL OR CHAR_LENGTH(TRIM(inventory_number)) > 0)",
                "Item inventory metadata"
            );
        }

        foreach ($this->definitions() as $table => $sql) {
            if (!$this->tableExists($table)) {
                $this->execute($sql, $table);
            }
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1010);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    /** @return array<string, string> */
    private function definitions(): array
    {
        $titles = $this->tables->workAlternateTitles();
        $containments = $this->tables->workContainments();
        $works = $this->tables->works();
        $collation = $this->database->get_charset_collate();

        return [
            $titles => "CREATE TABLE `{$titles}` ("
                . "work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "alternate_title VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "normalized_title VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "PRIMARY KEY (work_id,normalized_title),"
                . "KEY alternate_titles_by_title (normalized_title,work_id),"
                . "CONSTRAINT work_alternate_titles_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT alternate_title_non_empty CHECK (CHAR_LENGTH(TRIM(alternate_title)) > 0),"
                . "CONSTRAINT alternate_title_normalized_non_empty CHECK (CHAR_LENGTH(TRIM(normalized_title)) > 0)"
                . ") ENGINE=InnoDB {$collation}",
            $containments => "CREATE TABLE `{$containments}` ("
                . "parent_work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "contained_work_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
                . "contained_position BIGINT UNSIGNED NOT NULL,"
                . "PRIMARY KEY (parent_work_id,contained_work_id),"
                . "UNIQUE KEY work_containments_by_position (parent_work_id,contained_position),"
                . "KEY work_containments_by_contained (contained_work_id,parent_work_id),"
                . "CONSTRAINT work_containments_parent_fk FOREIGN KEY (parent_work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT work_containments_contained_fk FOREIGN KEY (contained_work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
                . "CONSTRAINT work_containments_not_self CHECK (parent_work_id <> contained_work_id),"
                . "CONSTRAINT work_containments_position_positive CHECK (contained_position >= 1)"
                . ") ENGINE=InnoDB {$collation}",
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $markers
     */
    private function assertKnownComponentState(
        string $table,
        array $columns,
        array $markers,
        CoreSchemaHealth $currentHealth
    ): void {
        $present = 0;
        foreach ($columns as $column) {
            $present += $this->hasColumn($table, $column) ? 1 : 0;
        }
        foreach ($markers as $marker) {
            $present += $this->hasSchemaMarker($table, $marker) ? 1 : 0;
        }

        if ($present !== 0 && !$currentHealth->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1010 retry found an unknown partial state for {$table}: "
                    . $currentHealth->summary()
            );
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    private function hasSchemaMarker(string $table, string $marker): bool
    {
        $indexes = (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s",
            DB_NAME,
            $table,
            $marker
        ));
        $constraints = (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
                . "WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s",
            DB_NAME,
            $table,
            $marker
        ));

        return $indexes > 0 || $constraints > 0;
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1010 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
