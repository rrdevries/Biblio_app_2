<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1020Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1019; }
    public function targetVersion(): int { return 1020; }

    public function assertPrecondition(): void
    {
        $table = $this->tables->itemArchivePeriods();
        $markers = [
            $this->hasColumn($table, "archive_reason_kind"),
            $this->hasColumn($table, "preserved_reason_text"),
            $this->hasColumn($table, "preserved_reason_value"),
            $this->hasConstraint($table, "item_archive_reason_kind_supported"),
            $this->hasConstraint($table, "item_archive_reason_shape"),
            $this->hasConstraint($table, "item_archive_preserved_text_valid"),
            $this->hasConstraint($table, "item_archive_preserved_value_valid"),
        ];
        $markerCount = count(array_filter($markers));

        if ($markerCount === 0) {
            $source = $this->health->inspectForVersion(1019);
            if (!$source->isHealthy()) {
                throw new CoreSchemaHealthException($source);
            }

            return;
        }

        $target = $this->health->inspectSchema1020ArchiveReasons();
        if ($markerCount !== count($markers) || !$target->isHealthy()) {
            throw new CoreSchemaMigrationException(
                "Schema 1020 retry found an unknown Item archive reason state: "
                    . $target->summary()
            );
        }
    }

    public function migrate(): void
    {
        $target = $this->health->inspectSchema1020ArchiveReasons();
        if ($target->isHealthy()) {
            return;
        }

        $table = $this->tables->itemArchivePeriods();
        $this->execute(
            "ALTER TABLE `{$table}` "
                . "DROP CONSTRAINT item_archive_reason_supported,"
                . "ADD archive_reason_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'native' AFTER archive_version,"
                . "MODIFY archive_reason VARCHAR(32) NULL,"
                . "ADD preserved_reason_text VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER archive_reason,"
                . "ADD preserved_reason_value VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER preserved_reason_text,"
                . "ADD CONSTRAINT item_archive_reason_kind_supported CHECK (archive_reason_kind IN ('native','preserved_historical')),"
                . "ADD CONSTRAINT item_archive_reason_shape CHECK ((archive_reason_kind='native' AND archive_reason IN ('sold','given_away','donated','lost','damaged_discarded','not_returned') AND preserved_reason_text IS NULL AND preserved_reason_value IS NULL) OR (archive_reason_kind='preserved_historical' AND archive_reason IS NULL AND preserved_reason_text IS NOT NULL)),"
                . "ADD CONSTRAINT item_archive_preserved_text_valid CHECK (preserved_reason_text IS NULL OR (CHAR_LENGTH(TRIM(preserved_reason_text)) > 0 AND CHAR_LENGTH(preserved_reason_text) <= 500)),"
                . "ADD CONSTRAINT item_archive_preserved_value_valid CHECK (preserved_reason_value IS NULL OR (CHAR_LENGTH(TRIM(preserved_reason_value)) > 0 AND CHAR_LENGTH(preserved_reason_value) <= 191))",
            "truth-preserving Item archive reasons"
        );
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1020);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate schema 1020 component {$component}: "
                    . $this->database->last_error
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

    private function hasConstraint(string $table, string $constraint): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
                . "WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s "
                . "AND CONSTRAINT_NAME=%s",
            DB_NAME,
            $table,
            $constraint
        )) === 1;
    }
}
