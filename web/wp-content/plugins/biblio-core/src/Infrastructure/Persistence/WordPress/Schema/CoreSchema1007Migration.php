<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Library\LibraryName;
use wpdb;

final readonly class CoreSchema1007Migration implements CoreSchemaMigration
{
    private const CHECK_NAME = "libraries_name_non_empty";
    private const ACTOR_INDEX = "memberships_by_user";

    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
        $this->healthChecker = new CoreSchemaHealthChecker(
            $database,
            $tableNames
        );
    }

    public function sourceVersion(): int { return 1006; }
    public function targetVersion(): int { return 1007; }

    public function assertPrecondition(): void
    {
        $base = $this->healthChecker->inspectForVersion(1006);

        if (!$base->isHealthy()) {
            throw new CoreSchemaHealthException($base);
        }

        $indexColumns = $this->actorIndexColumns();

        if ($indexColumns !== [] && $indexColumns !== ["user_id", "library_id"]) {
            throw new CoreSchemaMigrationException(
                "Schema 1007 retry found an unknown actor-membership index."
            );
        }

        $definition = $this->nameColumnDefinition();

        if ($definition === null) {
            if ($this->checkExists()) {
                throw new CoreSchemaMigrationException(
                    "Schema 1007 retry found a Library-name check without "
                    . "the Library-name column."
                );
            }

            return;
        }

        if (
            strtolower($definition->column_type) !== "varchar(191)"
            || strtolower((string) $definition->collation_name)
                !== "utf8mb4_bin"
            || !in_array($definition->is_nullable, ["YES", "NO"], true)
        ) {
            throw new CoreSchemaMigrationException(
                "Schema 1007 retry found an unknown Library-name column."
            );
        }

    }

    public function migrate(): void
    {
        $libraries = $this->tableNames->libraries();

        if ($this->nameColumnDefinition() === null) {
            $this->execute(
                "ALTER TABLE `{$libraries}` ADD COLUMN library_name "
                . "VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin "
                . "NULL AFTER library_id",
                "add nullable Library name"
            );
        }

        $default = LibraryName::PERSONAL_DEFAULT;
        $this->execute(
            $this->database->prepare(
                "UPDATE `{$libraries}` SET library_name = %s "
                . "WHERE library_name IS NULL "
                . "OR CHAR_LENGTH(TRIM(library_name)) = 0",
                $default
            ),
            "backfill Library names"
        );

        if ($this->nameColumnDefinition()?->is_nullable === "YES") {
            $this->execute(
                "ALTER TABLE `{$libraries}` MODIFY COLUMN library_name "
                . "VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin "
                . "NOT NULL",
                "make Library name required"
            );
        }

        if (!$this->checkExists()) {
            $this->execute(
                "ALTER TABLE `{$libraries}` ADD CONSTRAINT `"
                . self::CHECK_NAME . "` CHECK "
                . "(CHAR_LENGTH(TRIM(library_name)) > 0)",
                "add Library-name check"
            );
        }

        if ($this->actorIndexColumns() === []) {
            $memberships = $this->tableNames->memberships();
            $this->execute(
                "ALTER TABLE `{$memberships}` ADD INDEX `"
                . self::ACTOR_INDEX . "` (user_id, library_id)",
                "add actor-membership read index"
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1007);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function nameColumnDefinition(): ?object
    {
        $table = $this->tableNames->libraries();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, "
            . "COLLATION_NAME AS collation_name "
            . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s "
            . "AND TABLE_NAME = %s AND COLUMN_NAME = 'library_name'",
            DB_NAME,
            $table
        ));

        return is_object($row) ? $row : null;
    }

    private function checkExists(): bool
    {
        $table = $this->tableNames->libraries();

        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'CHECK'",
            DB_NAME,
            $table,
            self::CHECK_NAME
        )) === 1;
    }

    /** @return list<string> */
    private function actorIndexColumns(): array
    {
        $table = $this->tableNames->memberships();

        return array_map(
            "strval",
            $this->database->get_col($this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
                . "AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX",
                DB_NAME,
                $table,
                self::ACTOR_INDEX
            ))
        );
    }

    private function execute(string $sql, string $operation): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not {$operation}: {$this->database->last_error}"
            );
        }
    }
}
