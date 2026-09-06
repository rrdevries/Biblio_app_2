<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1016Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1015; }
    public function targetVersion(): int { return 1016; }

    public function assertPrecondition(): void
    {
        $source = $this->health->inspectForVersion(1015);
        if (!$source->isHealthy()) {
            throw new CoreSchemaHealthException($source);
        }

        $this->assertKnownColumn(
            $this->tables->works(),
            "work_title_status",
            "varchar(32)",
            "utf8mb4_bin",
            [null, "provisional"]
        );
        $this->assertKnownColumn(
            $this->tables->editions(),
            "edition_title",
            "varchar(512)",
            "utf8mb4_bin",
            [null]
        );
        $this->assertKnownConstraint(
            $this->tables->works(),
            "work_title_status_supported",
            "work_title_status IN ('provisional','librarian_confirmed')"
        );
        $this->assertKnownConstraint(
            $this->tables->editions(),
            "edition_title_non_empty",
            "CHAR_LENGTH(TRIM(edition_title)) > 0"
        );

        if (
            $this->hasColumn($this->tables->works(), "work_title_status")
            && (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tables->works()}` "
                    . "WHERE work_title_status IS NOT NULL "
                    . "AND work_title_status <> 'provisional'"
            ) > 0
        ) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 retry found Work title state not produced by its backfill."
            );
        }
        if (
            $this->hasColumn($this->tables->editions(), "edition_title")
            && (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tables->editions()}` e "
                    . "INNER JOIN `{$this->tables->works()}` w "
                    . "ON w.work_id=e.work_id WHERE e.edition_title IS NOT NULL "
                    . "AND BINARY e.edition_title <> BINARY w.work_title"
            ) > 0
        ) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 retry found Edition title data not produced by its backfill."
            );
        }
    }

    public function migrate(): void
    {
        $works = $this->tables->works();
        $editions = $this->tables->editions();

        if (!$this->hasColumn($works, "work_title_status")) {
            $this->execute(
                "ALTER TABLE `{$works}` ADD work_title_status "
                    . "VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL",
                "Work title status column"
            );
        }
        if (!$this->hasColumn($editions, "edition_title")) {
            $this->execute(
                "ALTER TABLE `{$editions}` ADD edition_title "
                    . "VARCHAR(512) CHARACTER SET utf8mb4 "
                    . "COLLATE utf8mb4_bin NULL AFTER work_id",
                "Edition title column"
            );
        }

        $this->execute(
            "UPDATE `{$works}` SET work_title_status='provisional' "
                . "WHERE work_title_status IS NULL",
            "Work title status backfill"
        );
        $this->execute(
            "UPDATE `{$editions}` e INNER JOIN `{$works}` w "
                . "ON w.work_id=e.work_id SET e.edition_title=w.work_title "
                . "WHERE e.edition_title IS NULL",
            "Edition title backfill"
        );

        if ($this->hasInvalidWorkStatus($works)) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 found an unsupported Work title status."
            );
        }
        if ($this->hasInvalidEditionTitle($editions)) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 could not establish a valid Edition title."
            );
        }

        $workAlterations = [];
        if (
            $this->columnIsNullable($works, "work_title_status")
            || $this->columnDefault($works, "work_title_status") !== "provisional"
        ) {
            $workAlterations[] = "MODIFY work_title_status VARCHAR(32) "
                . "CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL "
                . "DEFAULT 'provisional'";
        }
        if (!$this->hasConstraint($works, "work_title_status_supported")) {
            $workAlterations[] = "ADD CONSTRAINT work_title_status_supported "
                . "CHECK (work_title_status IN ('provisional','librarian_confirmed'))";
        }
        if ($workAlterations !== []) {
            $this->execute(
                "ALTER TABLE `{$works}` " . implode(",", $workAlterations),
                "Work title status contract"
            );
        }

        $editionAlterations = [];
        if ($this->columnIsNullable($editions, "edition_title")) {
            $editionAlterations[] = "MODIFY edition_title VARCHAR(512) "
                . "CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL";
        }
        if (!$this->hasConstraint($editions, "edition_title_non_empty")) {
            $editionAlterations[] = "ADD CONSTRAINT edition_title_non_empty "
                . "CHECK (CHAR_LENGTH(TRIM(edition_title)) > 0)";
        }
        if ($editionAlterations !== []) {
            $this->execute(
                "ALTER TABLE `{$editions}` " . implode(",", $editionAlterations),
                "Edition title contract"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1016);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    /** @param list<?string> $allowedDefaults */
    private function assertKnownColumn(
        string $table,
        string $column,
        string $expectedType,
        string $expectedCollation,
        array $allowedDefaults
    ): void {
        $row = $this->column($table, $column);
        if ($row === null) {
            return;
        }
        if (
            strtolower((string) $row->COLUMN_TYPE) !== $expectedType
            || strtolower((string) $row->COLLATION_NAME) !== $expectedCollation
            || !in_array((string) $row->IS_NULLABLE, ["YES", "NO"], true)
            || !in_array($this->normalizedDefault($row->COLUMN_DEFAULT), $allowedDefaults, true)
        ) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 retry found an unknown {$table}.{$column} state."
            );
        }
    }

    private function hasInvalidWorkStatus(string $works): bool
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$works}` WHERE work_title_status IS NULL "
                . "OR work_title_status NOT IN ('provisional','librarian_confirmed')"
        ) > 0;
    }

    private function hasInvalidEditionTitle(string $editions): bool
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$editions}` WHERE edition_title IS NULL "
                . "OR CHAR_LENGTH(TRIM(edition_title))=0"
        ) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->column($table, $column) !== null;
    }

    private function column(string $table, string $column): ?object
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT COLUMN_TYPE,COLLATION_NAME,IS_NULLABLE,COLUMN_DEFAULT "
                . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s "
                . "AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        ));

        return is_object($row) ? $row : null;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        return (string) $this->column($table, $column)?->IS_NULLABLE === "YES";
    }

    private function columnDefault(string $table, string $column): ?string
    {
        return $this->normalizedDefault(
            $this->column($table, $column)?->COLUMN_DEFAULT
        );
    }

    private function normalizedDefault(mixed $default): ?string
    {
        if ($default === null || $default === "NULL") {
            return null;
        }

        return is_string($default) ? trim($default, "'") : null;
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
                . "WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s "
                . "AND CONSTRAINT_NAME=%s AND CONSTRAINT_TYPE='CHECK'",
            DB_NAME,
            $table,
            $constraint
        )) === 1;
    }

    private function assertKnownConstraint(
        string $table,
        string $constraint,
        string $expectedClause
    ): void {
        $clause = $this->database->get_var($this->database->prepare(
            "SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc "
                . "INNER JOIN information_schema.CHECK_CONSTRAINTS cc "
                . "ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA "
                . "AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME "
                . "WHERE tc.CONSTRAINT_SCHEMA=%s AND tc.TABLE_NAME=%s "
                . "AND tc.CONSTRAINT_NAME=%s AND tc.CONSTRAINT_TYPE='CHECK'",
            DB_NAME,
            $table,
            $constraint
        ));
        if ($clause === null) {
            return;
        }
        if ($this->normalizeClause((string) $clause) !== $this->normalizeClause($expectedClause)) {
            throw new CoreSchemaMigrationException(
                "Schema 1016 retry found an unknown {$constraint} constraint."
            );
        }
    }

    private function normalizeClause(string $clause): string
    {
        return (string) preg_replace('/[`\s()]+/', '', strtolower($clause));
    }

    private function execute(string $sql, string $component): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate schema 1016 component {$component}: "
                    . $this->database->last_error
            );
        }
    }
}
