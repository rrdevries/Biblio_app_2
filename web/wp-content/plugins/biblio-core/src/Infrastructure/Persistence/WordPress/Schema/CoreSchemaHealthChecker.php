<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchemaHealthChecker
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function inspect(): CoreSchemaHealth
    {
        $issues = [];

        foreach ($this->tableNames->all() as $tableName) {
            if (!$this->tableExists($tableName)) {
                $issues[] = "Missing required table {$tableName}";
                continue;
            }

            $engine = $this->tableEngine($tableName);

            if (strtoupper($engine) !== "INNODB") {
                $issues[] = "Table {$tableName} expected engine InnoDB; found "
                    . ($engine === "" ? "unknown" : $engine);
            }

            $this->inspectColumns($tableName, $issues);
            $this->inspectIndexes($tableName, $issues);
            $this->inspectForeignKeys($tableName, $issues);
            $this->inspectChecks($tableName, $issues);
        }

        return new CoreSchemaHealth($issues);
    }

    /** @param list<string> $issues */
    private function inspectColumns(string $tableName, array &$issues): void
    {
        $expectedColumns = $this->expectedColumns()[$tableName] ?? [];
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, "
            . "IS_NULLABLE AS is_nullable, COLLATION_NAME AS collation_name, "
            . "EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression "
            . "FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        ), ARRAY_A);
        $actualColumns = [];

        foreach ($rows as $row) {
            $actualColumns[$row["column_name"]] = $row;
        }

        foreach ($expectedColumns as $columnName => $expected) {
            if (!isset($actualColumns[$columnName])) {
                $issues[] = "Table {$tableName} missing required column "
                    . $columnName;
                continue;
            }

            $actual = $actualColumns[$columnName];

            if (strtolower($actual["column_type"]) !== $expected["type"]) {
                $issues[] = "Column {$tableName}.{$columnName} expected type "
                    . $expected["type"] . "; found "
                    . strtolower($actual["column_type"]);
            }

            if ($actual["is_nullable"] !== $expected["nullable"]) {
                $issues[] = "Column {$tableName}.{$columnName} expected "
                    . "nullable={$expected['nullable']}; found "
                    . $actual["is_nullable"];
            }

            if (
                isset($expected["collation"])
                && strtolower((string) $actual["collation_name"])
                    !== $expected["collation"]
            ) {
                $issues[] = "Column {$tableName}.{$columnName} expected "
                    . "collation {$expected['collation']}; found "
                    . ((string) $actual["collation_name"] ?: "none");
            }

            if (isset($expected["extra"])) {
                $actualExtra = strtoupper(trim((string) $actual["extra"]));

                if ($actualExtra !== $expected["extra"]) {
                    $issues[] = "Column {$tableName}.{$columnName} expected "
                        . "{$expected['extra']}; found "
                        . ($actualExtra === "" ? "no generated definition" : $actualExtra);
                }
            }

            if (isset($expected["expression"])) {
                $actualExpression = $this->normalizeExpression(
                    (string) $actual["generation_expression"]
                );
                $expectedExpression = $this->normalizeExpression(
                    $expected["expression"]
                );

                if ($actualExpression !== $expectedExpression) {
                    $issues[] = "Column {$tableName}.{$columnName} has an "
                        . "unexpected generation expression";
                }
            }
        }
    }

    /** @param list<string> $issues */
    private function inspectIndexes(string $tableName, array &$issues): void
    {
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, "
            . "SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name "
            . "FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            DB_NAME,
            $tableName
        ), ARRAY_A);
        $actualIndexes = [];

        foreach ($rows as $row) {
            $name = $row["index_name"];
            $actualIndexes[$name]["unique"] = (int) $row["non_unique"] === 0;
            $actualIndexes[$name]["columns"][] = $row["column_name"];
        }

        foreach (($this->expectedIndexes()[$tableName] ?? []) as $name => $expected) {
            if (!isset($actualIndexes[$name])) {
                $issues[] = "Table {$tableName} missing required index {$name}";
                continue;
            }

            $actual = $actualIndexes[$name];

            if (
                $actual["unique"] !== $expected["unique"]
                || $actual["columns"] !== $expected["columns"]
            ) {
                $issues[] = "Index {$tableName}.{$name} expected "
                    . ($expected["unique"] ? "unique " : "non-unique ")
                    . "columns (" . implode(", ", $expected["columns"])
                    . "); found "
                    . ($actual["unique"] ? "unique " : "non-unique ")
                    . "columns (" . implode(", ", $actual["columns"])
                    . ")";
            }
        }
    }

    /** @param list<string> $issues */
    private function inspectForeignKeys(string $tableName, array &$issues): void
    {
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT k.CONSTRAINT_NAME AS constraint_name, "
            . "k.COLUMN_NAME AS column_name, "
            . "k.REFERENCED_TABLE_NAME AS referenced_table, "
            . "k.REFERENCED_COLUMN_NAME AS referenced_column, "
            . "r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule "
            . "FROM information_schema.KEY_COLUMN_USAGE k "
            . "INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
            . "ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA "
            . "AND r.TABLE_NAME = k.TABLE_NAME "
            . "AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME "
            . "WHERE k.CONSTRAINT_SCHEMA = %s AND k.TABLE_NAME = %s "
            . "AND k.REFERENCED_TABLE_NAME IS NOT NULL "
            . "ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            DB_NAME,
            $tableName
        ), ARRAY_A);
        $actual = [];

        foreach ($rows as $row) {
            $name = $row["constraint_name"];
            $actual[$name]["columns"][] = $row["column_name"];
            $actual[$name]["referenced_table"] = $row["referenced_table"];
            $actual[$name]["referenced_columns"][] = $row["referenced_column"];
            $actual[$name]["update"] = strtoupper($row["update_rule"]);
            $actual[$name]["delete"] = strtoupper($row["delete_rule"]);
        }

        $unmatched = array_values($actual);

        foreach (($this->expectedForeignKeys()[$tableName] ?? []) as $expected) {
            $match = null;

            foreach ($unmatched as $key => $candidate) {
                if ($candidate === $expected) {
                    $match = $key;
                    break;
                }
            }

            if ($match === null) {
                $issues[] = "Table {$tableName} missing required foreign key "
                    . "(" . implode(", ", $expected["columns"]) . ") -> "
                    . $expected["referenced_table"] . " ("
                    . implode(", ", $expected["referenced_columns"])
                    . ") ON UPDATE {$expected['update']} ON DELETE "
                    . $expected["delete"];
                continue;
            }

            unset($unmatched[$match]);
        }
    }

    /** @param list<string> $issues */
    private function inspectChecks(string $tableName, array &$issues): void
    {
        $rows = $this->database->get_col($this->database->prepare(
            "SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c "
            . "INNER JOIN information_schema.TABLE_CONSTRAINTS t "
            . "ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA "
            . "AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME "
            . "WHERE t.CONSTRAINT_SCHEMA = %s AND t.TABLE_NAME = %s "
            . "AND t.CONSTRAINT_TYPE = 'CHECK'",
            DB_NAME,
            $tableName
        ));
        $actualChecks = array_map(
            fn (string $check): string => $this->normalizeExpression($check),
            $rows
        );

        foreach (($this->expectedChecks()[$tableName] ?? []) as $expected) {
            $normalized = $this->normalizeExpression($expected);

            if (!in_array($normalized, $actualChecks, true)) {
                $issues[] = "Table {$tableName} missing required CHECK: "
                    . $expected . "; found ["
                    . implode(", ", $actualChecks) . "]";
            }
        }
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function expectedColumns(): array
    {
        $id = [
            "type" => "varchar(191)",
            "nullable" => "NO",
            "collation" => "utf8mb4_bin",
        ];
        $nullableId = [
            "type" => "varchar(191)",
            "nullable" => "YES",
            "collation" => "utf8mb4_bin",
        ];
        $generatedId = $nullableId + ["extra" => "STORED GENERATED"];

        return [
            $this->tableNames->libraries() => [
                "library_id" => $id,
                "library_type" => ["type" => "varchar(32)", "nullable" => "NO"],
                "library_status" => ["type" => "varchar(32)", "nullable" => "NO"],
            ],
            $this->tableNames->memberships() => [
                "library_id" => $id,
                "user_id" => $id,
                "membership_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "management_role" => ["type" => "varchar(32)", "nullable" => "NO"],
                "use_access" => ["type" => "varchar(32)", "nullable" => "NO"],
                "additional_permissions" => ["type" => "longtext", "nullable" => "NO"],
                "active_owner_library_id" => $generatedId + [
                    "expression" => "CASE WHEN management_role = 'owner' "
                        . "AND membership_status = 'active' "
                        . "THEN library_id ELSE NULL END",
                ],
            ],
            $this->tableNames->personalLibraryDesignations() => [
                "user_id" => $id,
                "library_id" => $id,
            ],
            $this->tableNames->works() => [
                "work_id" => $id,
                "work_title" => ["type" => "varchar(512)", "nullable" => "NO"],
            ],
            $this->tableNames->editions() => [
                "edition_id" => $id,
                "work_id" => $id,
            ],
            $this->tableNames->items() => [
                "item_id" => $id,
                "library_id" => $id,
                "edition_id" => $id,
                "item_status" => ["type" => "varchar(32)", "nullable" => "NO"],
            ],
            $this->tableNames->externalLoans() => [
                "external_loan_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "loan_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "borrowed_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "due_at" => ["type" => "datetime(6)", "nullable" => "YES"],
            ],
            $this->tableNames->readingRounds() => [
                "reading_round_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "item_id" => $nullableId,
                "external_loan_id" => $nullableId,
                "round_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "started_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "active_item_user_id" => $generatedId + [
                    "expression" => "CASE WHEN round_status = 'active' "
                        . "AND item_id IS NOT NULL THEN user_id ELSE NULL END",
                ],
                "active_external_loan_user_id" => $generatedId + [
                    "expression" => "CASE WHEN round_status = 'active' "
                        . "AND external_loan_id IS NOT NULL "
                        . "THEN user_id ELSE NULL END",
                ],
            ],
        ];
    }

    /** @return array<string, array<string, array{unique: bool, columns: list<string>}>> */
    private function expectedIndexes(): array
    {
        return [
            $this->tableNames->libraries() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id"]],
            ],
            $this->tableNames->memberships() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id", "user_id"]],
                "one_active_owner" => ["unique" => true, "columns" => ["active_owner_library_id"]],
            ],
            $this->tableNames->personalLibraryDesignations() => [
                "PRIMARY" => ["unique" => true, "columns" => ["user_id"]],
                "one_personal_user_per_library" => ["unique" => true, "columns" => ["library_id"]],
            ],
            $this->tableNames->works() => [
                "PRIMARY" => ["unique" => true, "columns" => ["work_id"]],
            ],
            $this->tableNames->editions() => [
                "PRIMARY" => ["unique" => true, "columns" => ["edition_id"]],
                "editions_by_work" => ["unique" => false, "columns" => ["work_id"]],
            ],
            $this->tableNames->items() => [
                "PRIMARY" => ["unique" => true, "columns" => ["item_id"]],
                "items_by_library" => ["unique" => false, "columns" => ["library_id"]],
                "items_by_edition" => ["unique" => false, "columns" => ["edition_id"]],
            ],
            $this->tableNames->externalLoans() => [
                "PRIMARY" => ["unique" => true, "columns" => ["external_loan_id"]],
                "external_loans_by_user" => ["unique" => false, "columns" => ["user_id"]],
                "external_loans_by_work" => ["unique" => false, "columns" => ["work_id"]],
            ],
            $this->tableNames->readingRounds() => [
                "PRIMARY" => ["unique" => true, "columns" => ["reading_round_id"]],
                "reading_rounds_by_user" => ["unique" => false, "columns" => ["user_id"]],
                "reading_rounds_by_work" => ["unique" => false, "columns" => ["work_id"]],
                "one_active_item_round_per_user" => [
                    "unique" => true,
                    "columns" => ["active_item_user_id", "item_id"],
                ],
                "one_active_external_round_per_user" => [
                    "unique" => true,
                    "columns" => ["active_external_loan_user_id", "external_loan_id"],
                ],
            ],
        ];
    }

    /** @return array<string, list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, update: string, delete: string}>> */
    private function expectedForeignKeys(): array
    {
        $restrict = static fn (
            array $columns,
            string $referencedTable,
            array $referencedColumns
        ): array => [
            "columns" => $columns,
            "referenced_table" => $referencedTable,
            "referenced_columns" => $referencedColumns,
            "update" => "RESTRICT",
            "delete" => "RESTRICT",
        ];

        return [
            $this->tableNames->memberships() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
            ],
            $this->tableNames->personalLibraryDesignations() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
                $restrict(
                    ["library_id", "user_id"],
                    $this->tableNames->memberships(),
                    ["library_id", "user_id"]
                ),
            ],
            $this->tableNames->editions() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
            ],
            $this->tableNames->items() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
                $restrict(["edition_id"], $this->tableNames->editions(), ["edition_id"]),
            ],
            $this->tableNames->externalLoans() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
            ],
            $this->tableNames->readingRounds() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $restrict(["item_id"], $this->tableNames->items(), ["item_id"]),
                $restrict(
                    ["external_loan_id"],
                    $this->tableNames->externalLoans(),
                    ["external_loan_id"]
                ),
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function expectedChecks(): array
    {
        return [
            $this->tableNames->libraries() => [
                "library_type = 'private_library'",
                "library_status = 'active'",
            ],
            $this->tableNames->memberships() => [
                "membership_status IN ('active', 'inactive')",
                "management_role IN ('owner', 'manager', 'member')",
                "use_access IN ('direct', 'borrow', 'view_only')",
                "management_role <> 'owner' OR use_access = 'direct'",
                "JSON_VALID(additional_permissions)",
            ],
            $this->tableNames->works() => [
                "CHAR_LENGTH(TRIM(work_title)) > 0",
            ],
            $this->tableNames->items() => [
                "item_status = 'active'",
            ],
            $this->tableNames->externalLoans() => [
                "CHAR_LENGTH(TRIM(user_id)) > 0",
                "loan_status = 'active'",
            ],
            $this->tableNames->readingRounds() => [
                "round_status = 'active'",
                "item_id IS NOT NULL AND external_loan_id IS NULL OR "
                    . "item_id IS NULL AND external_loan_id IS NOT NULL",
            ],
        ];
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        )) === 1;
    }

    private function tableEngine(string $tableName): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT ENGINE FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        ));
    }

    private function normalizeExpression(string $expression): string
    {
        $normalized = "";
        $quote = null;
        $length = strlen($expression);

        for ($position = 0; $position < $length; $position++) {
            $character = $expression[$position];

            if ($quote !== null) {
                $normalized .= $character;

                if ($character === "\\" && $position + 1 < $length) {
                    $normalized .= $expression[++$position];
                    continue;
                }

                if ($character === $quote) {
                    if (
                        $position + 1 < $length
                        && $expression[$position + 1] === $quote
                    ) {
                        $normalized .= $expression[++$position];
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $normalized .= $character;
                continue;
            }

            if ($character === "`" || preg_match('/\s/', $character) === 1) {
                continue;
            }

            $normalized .= strtolower($character);
        }

        while (
            str_starts_with($normalized, "(")
            && str_ends_with($normalized, ")")
            && $this->outerParenthesesWrapExpression($normalized)
        ) {
            $normalized = substr($normalized, 1, -1);
        }

        return $normalized;
    }

    private function outerParenthesesWrapExpression(string $expression): bool
    {
        $depth = 0;
        $length = strlen($expression);
        $quote = null;

        for ($position = 0; $position < $length; $position++) {
            $character = $expression[$position];

            if ($quote !== null) {
                if ($character === "\\" && $position + 1 < $length) {
                    $position++;
                    continue;
                }

                if ($character === $quote) {
                    if (
                        $position + 1 < $length
                        && $expression[$position + 1] === $quote
                    ) {
                        $position++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === "(") {
                $depth++;
            } elseif ($character === ")") {
                $depth--;
            }

            if ($depth === 0 && $position < $length - 1) {
                return false;
            }
        }

        return $depth === 0;
    }
}
