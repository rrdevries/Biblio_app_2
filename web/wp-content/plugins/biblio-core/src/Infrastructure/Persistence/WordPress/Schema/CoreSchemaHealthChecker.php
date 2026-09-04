<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Library\LibraryId;
use wpdb;

final readonly class CoreSchemaHealthChecker
{
    private ClassificationSeedEvolution $seedEvolution;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames,
        ?ClassificationSeedEvolution $seedEvolution = null
    ) {
        $this->seedEvolution = $seedEvolution
            ?? WpdbClassificationSeedEvolutionFactory::create(
                $database,
                $tableNames
            );
    }

    public function inspectForVersion(int $expectedVersion): CoreSchemaHealth
    {
        $health = match ($expectedVersion) {
            1000 => $this->inspectTables($this->tableNames->all(), true, 1000),
            1001 => $this->inspectTables($this->tableNames->schema1001(), true, 1001),
            1002 => $this->inspectTables($this->tableNames->schema1001(), true, 1002),
            1003 => $this->inspectTables($this->tableNames->schema1001(), true, 1003),
            1004 => $this->inspectTables($this->tableNames->schema1004(), true, 1004),
            1005 => $this->inspectTables($this->tableNames->schema1005(), true, 1005),
            1006 => $this->inspectTables($this->tableNames->schema1006(), true, 1006),
            1007 => $this->inspectTables($this->tableNames->schema1006(), true, 1007),
            1008 => $this->inspectTables($this->tableNames->schema1008(), true, 1008),
            1009 => $this->inspectTables($this->tableNames->schema1009(), true, 1009),
            1010 => $this->inspectTables($this->tableNames->schema1010(), true, 1010),
            1011 => $this->inspectTables($this->tableNames->schema1011(), true, 1011),
            1012 => $this->inspectTables($this->tableNames->schema1012(), true, 1012),
            1013 => $this->inspectTables($this->tableNames->schema1013(), true, 1013),
            default => throw new CoreSchemaMigrationException(
                "No explicit Biblio Core schema-health contract exists for "
                . "schema version {$expectedVersion}."
            ),
        };

        if (
            $expectedVersion < 1001
            || !$health->isHealthy()
        ) {
            return $health;
        }

        return new CoreSchemaHealth(
            $health->errors(),
            $this->classificationSeedWarnings()
        );
    }

    /** @return list<CoreSchemaHealthWarning> */
    private function classificationSeedWarnings(): array
    {
        $warnings = [];

        $libraries = $this->tableNames->libraries();
        $storedIds = $this->database->get_col(
            "SELECT library_id FROM `{$libraries}` ORDER BY library_id"
        );

        foreach ($storedIds as $storedId) {
            $libraryId = new LibraryId((string) $storedId);
            $ambiguities = $this->seedEvolution->ambiguities($libraryId);

            foreach ($ambiguities as $ambiguity) {
                $warnings[] = new CoreSchemaHealthWarning(
                    "classification_seed_adoption_ambiguous",
                    "Classification seed adoption is ambiguous and was not applied.",
                    [
                        "library_id" => $ambiguity->libraryId()->value(),
                        "taxonomy_type" => $ambiguity->taxonomyType()->value,
                        "seed_key" => $ambiguity->seedKey()->value(),
                        "candidate_term_ids" => $ambiguity->candidateTermIds(),
                    ]
                );
            }
        }

        return $warnings;
    }

    public function inspectExistingSchema1001Additions(): CoreSchemaHealth
    {
        return $this->inspectTables(
            $this->tableNames->schema1001Additions(),
            false,
            1001
        );
    }

    public function inspectExistingSchema1009Additions(): CoreSchemaHealth
    {
        return $this->inspectTables(
            $this->tableNames->schema1009Additions(),
            false,
            1009
        );
    }

    public function inspectExistingSchema1010Additions(): CoreSchemaHealth
    {
        return $this->inspectTables(
            $this->tableNames->schema1010Additions(),
            false,
            1010
        );
    }

    public function inspectSchema1010EditionMetadata(): CoreSchemaHealth
    {
        return $this->inspectTables(
            [$this->tableNames->editions()],
            true,
            1010
        );
    }

    public function inspectSchema1010ItemMetadata(): CoreSchemaHealth
    {
        return $this->inspectTables(
            [$this->tableNames->items()],
            true,
            1010
        );
    }

    public function inspectExistingSchema1011Additions(): CoreSchemaHealth
    {
        return $this->inspectTables(
            $this->tableNames->schema1011Additions(),
            false,
            1011
        );
    }

    public function inspectSchema1011ItemLocation(): CoreSchemaHealth
    {
        return $this->inspectTables([$this->tableNames->items()], true, 1011);
    }

    public function inspectExistingSchema1012Additions(): CoreSchemaHealth
    {
        return $this->inspectTables($this->tableNames->schema1012Additions(), false, 1012);
    }

    public function inspectSchema1012ItemLifecycle(): CoreSchemaHealth
    {
        return $this->inspectTables([$this->tableNames->items()], true, 1012);
    }

    public function inspectExistingSchema1013Additions(): CoreSchemaHealth
    {
        return $this->inspectTables($this->tableNames->schema1013Additions(), false, 1013);
    }

    /** @param list<string> $tableNames */
    private function inspectTables(
        array $tableNames,
        bool $missingIsError,
        int $schemaVersion
    ): CoreSchemaHealth
    {
        $issues = [];

        foreach ($tableNames as $tableName) {
            if (!$this->tableExists($tableName)) {
                if ($missingIsError) {
                    $issues[] = "Missing required table {$tableName}";
                }

                continue;
            }

            $engine = $this->tableEngine($tableName);

            if (strtoupper($engine) !== "INNODB") {
                $issues[] = "Table {$tableName} expected engine InnoDB; found "
                    . ($engine === "" ? "unknown" : $engine);
            }

            $this->inspectColumns($tableName, $issues, $schemaVersion);
            $this->inspectIndexes($tableName, $issues, $schemaVersion);
            $this->inspectForeignKeys($tableName, $issues, $schemaVersion);
            $this->inspectChecks($tableName, $issues, $schemaVersion);
        }

        if ($schemaVersion >= 1006 && $this->tableExists($this->tableNames->nextReadingLists())
            && $this->tableExists($this->tableNames->nextReadingEntries())
            && $this->nextReadingDataColumnsExist()) {
            $this->inspectNextReadingData($issues);
            $this->inspectNextReadingTriggers($issues, $schemaVersion);
        }

        if ($schemaVersion >= 1007 && $this->tableExists($this->tableNames->libraries())) {
            $this->inspectLibraryIdentityData($issues);
        }

        return new CoreSchemaHealth($issues);
    }

    /** @param list<string> $issues */
    private function inspectColumns(
        string $tableName,
        array &$issues,
        int $schemaVersion
    ): void
    {
        $expectedColumns = $this->expectedColumns($schemaVersion)[$tableName] ?? [];
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
    private function inspectIndexes(
        string $tableName,
        array &$issues,
        int $schemaVersion
    ): void
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

        foreach (($this->expectedIndexes($schemaVersion)[$tableName] ?? []) as $name => $expected) {
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
    private function inspectForeignKeys(
        string $tableName,
        array &$issues,
        int $schemaVersion
    ): void
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

        foreach (($this->expectedForeignKeys($schemaVersion)[$tableName] ?? []) as $expected) {
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
    private function inspectChecks(
        string $tableName,
        array &$issues,
        int $schemaVersion
    ): void
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

        foreach (($this->expectedChecks($schemaVersion)[$tableName] ?? []) as $expected) {
            $normalized = $this->normalizeExpression($expected);

            if (
                $tableName === $this->tableNames->items()
                && $normalized === $this->normalizeExpression("item_status = 'active'")
                && in_array(
                    $this->normalizeExpression("item_status IN ('active', 'archived')"),
                    $actualChecks,
                    true
                )
            ) {
                continue;
            }

            if (!in_array($normalized, $actualChecks, true)) {
                $issues[] = "Table {$tableName} missing required CHECK: "
                    . $expected . "; found ["
                    . implode(", ", $actualChecks) . "]";
            }
        }
    }

    /** @param list<string> $issues */
    private function inspectNextReadingData(array &$issues): void
    {
        $entries = $this->tableNames->nextReadingEntries();
        $invalidOrder = $this->database->get_var(
            "SELECT user_id FROM `{$entries}` GROUP BY user_id "
            . "HAVING MIN(position) <> 1 OR MAX(position) <> COUNT(*) "
            . "OR COUNT(DISTINCT position) <> COUNT(*) LIMIT 1"
        );
        if (is_string($invalidOrder)) {
            $issues[] = "Next Reading positions are not contiguous for user {$invalidOrder}";
        }
    }

    private function nextReadingDataColumnsExist(): bool
    {
        $columns = $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $this->tableNames->nextReadingEntries()
        ));
        return count(array_intersect(["user_id", "position"], $columns)) === 2;
    }

    /** @param list<string> $issues */
    private function inspectNextReadingTriggers(array &$issues, int $schemaVersion): void
    {
        $triggers = [
            $this->tableNames->nextReadingInsertTrigger() => "INSERT",
            $this->tableNames->nextReadingUpdateTrigger() => "UPDATE",
        ];
        if ($schemaVersion >= 1008) {
            $triggers += [
                $this->tableNames->nextReadingUndoInsertTrigger() => "INSERT",
                $this->tableNames->nextReadingUndoUpdateTrigger() => "UPDATE",
            ];
        }
        foreach ($triggers as $trigger => $event) {
            $undoTrigger = in_array($trigger, [
                $this->tableNames->nextReadingUndoInsertTrigger(),
                $this->tableNames->nextReadingUndoUpdateTrigger(),
            ], true);
            $expectedTable = $undoTrigger
                ? $this->tableNames->nextReadingUndo()
                : $this->tableNames->nextReadingEntries();
            $expectedMessage = $schemaVersion >= 1008
                ? "Invalid Next Reading preferred source shape"
                : "Invalid Next Reading live source shape";
            $row = $this->database->get_row($this->database->prepare(
                "SELECT EVENT_MANIPULATION AS event_name,ACTION_TIMING AS timing,EVENT_OBJECT_TABLE AS table_name,ACTION_STATEMENT AS statement "
                . "FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND TRIGGER_NAME=%s",
                DB_NAME,
                $trigger
            ), ARRAY_A);
            if (!is_array($row)) {
                $issues[] = "Missing required trigger {$trigger}";
                continue;
            }
            if ($row["event_name"] !== $event || $row["timing"] !== "BEFORE"
                || $row["table_name"] !== $expectedTable
                || !str_contains((string) $row["statement"], $expectedMessage)) {
                $issues[] = "Trigger {$trigger} has an unexpected definition";
            }
        }
    }

    /** @param list<string> $issues */
    private function inspectLibraryIdentityData(array &$issues): void
    {
        $libraries = $this->tableNames->libraries();
        $invalid = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$libraries}` "
            . "WHERE library_name IS NULL "
            . "OR CHAR_LENGTH(TRIM(library_name)) = 0"
        );

        if ($invalid > 0) {
            $issues[] = "Library identity contains {$invalid} missing or empty name(s)";
        }
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function expectedColumns(int $schemaVersion): array
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
        $generatedCollectionName = [
            "type" => "varchar(80)",
            "nullable" => "YES",
            "collation" => "utf8mb4_bin",
            "extra" => "STORED GENERATED",
        ];

        $libraryColumns = [
                "library_id" => $id,
                "library_type" => ["type" => "varchar(32)", "nullable" => "NO"],
                "library_status" => ["type" => "varchar(32)", "nullable" => "NO"],
        ];

        if ($schemaVersion >= 1007) {
            $libraryColumns = [
                "library_id" => $id,
                "library_name" => $id,
                "library_type" => ["type" => "varchar(32)", "nullable" => "NO"],
                "library_status" => ["type" => "varchar(32)", "nullable" => "NO"],
            ];
        }

        return [
            $this->tableNames->libraries() => $libraryColumns,
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
            $this->tableNames->authors() => [
                "author_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
            ],
            $this->tableNames->workContributors() => [
                "work_id" => $id,
                "author_id" => $id,
                "contributor_role" => ["type" => "varchar(32)", "nullable" => "NO"],
                "contributor_position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
            ],
            $this->tableNames->series() => [
                "series_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
            ],
            $this->tableNames->workSeries() => [
                "work_id" => $id,
                "series_id" => $id,
                "series_position" => [
                    "type" => "decimal(20,6) unsigned",
                    "nullable" => "YES",
                ],
            ],
            $this->tableNames->workAlternateTitles() => [
                "work_id" => $id,
                "alternate_title" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "normalized_title" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
            ],
            $this->tableNames->workContainments() => [
                "parent_work_id" => $id,
                "contained_work_id" => $id,
                "contained_position" => [
                    "type" => "bigint(20) unsigned",
                    "nullable" => "NO",
                ],
            ],
            $this->tableNames->editions() => [
                "edition_id" => $id,
                "work_id" => $id,
                ...($schemaVersion >= 1010 ? [
                    "isbn_10" => [
                        "type" => "varchar(10)",
                        "nullable" => "YES",
                        "collation" => "utf8mb4_bin",
                    ],
                    "isbn_13" => [
                        "type" => "varchar(13)",
                        "nullable" => "YES",
                        "collation" => "utf8mb4_bin",
                    ],
                    "explicitly_no_isbn" => [
                        "type" => "tinyint(3) unsigned",
                        "nullable" => "NO",
                    ],
                ] : []),
            ],
            $this->tableNames->items() => [
                "item_id" => $id,
                "library_id" => $id,
                "edition_id" => $id,
                "item_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                ...($schemaVersion >= 1010 ? [
                    "inventory_number" => $nullableId,
                ] : []),
                ...($schemaVersion >= 1011 ? [
                    "location_id" => $nullableId,
                ] : []),
                ...($schemaVersion >= 1012 ? [
                    "item_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                ] : []),
            ],
            $this->tableNames->itemArchivePeriods() => [
                "library_id" => $id,
                "item_id" => $id,
                "archive_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "archive_reason" => ["type" => "varchar(32)", "nullable" => "NO"],
                "archived_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "restore_version" => ["type" => "bigint(20) unsigned", "nullable" => "YES"],
                "restored_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                "open_item_id" => $generatedId + [
                    "expression" => "CASE WHEN restored_at IS NULL THEN item_id ELSE NULL END",
                ],
            ],
            $this->tableNames->collections() => [
                "library_id" => $id,
                "collection_id" => $id,
                "collection_name" => ["type" => "varchar(80)", "nullable" => "NO"],
                "normalized_name" => ["type" => "varchar(80)", "nullable" => "NO", "collation" => "utf8mb4_bin"],
                "description" => ["type" => "varchar(300)", "nullable" => "YES"],
                "collection_status" => ["type" => "varchar(16)", "nullable" => "NO"],
                "collection_position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "collection_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "active_normalized_name" => $generatedCollectionName + [
                    "expression" => "CASE WHEN collection_status = 'active' THEN normalized_name ELSE NULL END",
                ],
            ],
            $this->tableNames->collectionMemberships() => [
                "library_id" => $id,
                "membership_id" => $id,
                "collection_id" => $id,
                "item_id" => $id,
                "membership_status" => ["type" => "varchar(16)", "nullable" => "NO"],
                "item_position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "added_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "ended_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                "end_reason" => ["type" => "varchar(32)", "nullable" => "YES"],
                "active_item_id" => $generatedId + [
                    "expression" => "CASE WHEN membership_status = 'active' THEN item_id ELSE NULL END",
                ],
            ],
            $this->tableNames->locations() => [
                "library_id" => $id,
                "location_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
            ],
            $this->tableNames->externalLoans() => [
                "external_loan_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "loan_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "borrowed_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "due_at" => ["type" => "datetime(6)", "nullable" => "YES"],
            ],
            $this->tableNames->readingRounds() => $schemaVersion < 1003
                ? [
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
                ]
                : [
                    "reading_round_id" => $id,
                    "user_id" => $id,
                    "work_id" => $id,
                    "item_id" => $nullableId,
                    "external_loan_id" => $nullableId,
                    "started_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                    "round_outcome" => ["type" => "varchar(16)", "nullable" => "YES"],
                    "provenance" => ["type" => "varchar(32)", "nullable" => "NO"],
                    "reading_started_year" => ["type" => "smallint(5) unsigned", "nullable" => "YES"],
                    "reading_started_month" => ["type" => "tinyint(3) unsigned", "nullable" => "YES"],
                    "reading_started_day" => ["type" => "tinyint(3) unsigned", "nullable" => "YES"],
                    "reading_finished_year" => ["type" => "smallint(5) unsigned", "nullable" => "YES"],
                    "reading_finished_month" => ["type" => "tinyint(3) unsigned", "nullable" => "YES"],
                    "reading_finished_day" => ["type" => "tinyint(3) unsigned", "nullable" => "YES"],
                    "created_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                    "updated_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                    "ended_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                    "round_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                    "active_item_user_id" => $generatedId + [
                        "expression" => "CASE WHEN round_outcome IS NULL "
                            . "AND item_id IS NOT NULL THEN user_id ELSE NULL END",
                    ],
                    "active_external_loan_user_id" => $generatedId + [
                        "expression" => "CASE WHEN round_outcome IS NULL "
                            . "AND external_loan_id IS NOT NULL "
                            . "THEN user_id ELSE NULL END",
                    ],
                ],
            $this->tableNames->privateNotes() => [
                "private_note_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "reading_round_id" => $nullableId,
                "note_content" => ["type" => "text", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "note_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
            ],
            $this->tableNames->ratings() => [
                "rating_id" => $id, "user_id" => $id, "work_id" => $id,
                "reading_round_id" => $nullableId,
                "rating_half_units" => ["type" => "tinyint(3) unsigned", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "rating_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "unlinked_work_id" => $generatedId + [
                    "expression" => "CASE WHEN reading_round_id IS NULL THEN work_id ELSE NULL END",
                ],
            ],
            $this->tableNames->reviews() => [
                "review_id" => $id, "user_id" => $id, "work_id" => $id,
                "reading_round_id" => $nullableId,
                "review_content" => ["type" => "text", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "review_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "unlinked_work_id" => $generatedId + [
                    "expression" => "CASE WHEN reading_round_id IS NULL THEN work_id ELSE NULL END",
                ],
            ],
            $this->tableNames->contributionPublications() => [
                "publication_id" => $id, "library_id" => $id,
                "rating_id" => $nullableId, "review_id" => $nullableId,
                "author_status" => ["type" => "varchar(16)", "nullable" => "NO"],
                "moderation_status" => ["type" => "varchar(16)", "nullable" => "NO"],
                "moderation_reason" => ["type" => "text", "nullable" => "YES"],
                "moderator_user_id" => $nullableId,
                "moderated_at" => ["type" => "datetime(6)", "nullable" => "YES"],
                "published_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "publication_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "active_rating_id" => $generatedId + [
                    "expression" => "CASE WHEN rating_id IS NOT NULL AND author_status = 'active' AND moderation_status <> 'removed' THEN rating_id ELSE NULL END",
                ],
                "active_review_id" => $generatedId + [
                    "expression" => "CASE WHEN review_id IS NOT NULL AND author_status = 'active' AND moderation_status <> 'removed' THEN review_id ELSE NULL END",
                ],
            ],
            $this->tableNames->nextReadingLists() => [
                "user_id" => $id,
                "list_version" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "updated_at" => ["type" => "datetime(6)", "nullable" => "NO"],
            ],
            $this->tableNames->nextReadingEntries() => $schemaVersion < 1008 ? [
                "entry_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "target_type" => ["type" => "varchar(32)", "nullable" => "NO"],
                "source_id_snapshot" => $nullableId,
                "source_library_id_snapshot" => $nullableId,
                "item_id" => $nullableId,
                "external_loan_id" => $nullableId,
                "position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "work_target_id" => $generatedId + [
                    "expression" => "CASE WHEN target_type = 'work' THEN work_id ELSE NULL END",
                ],
                "item_target_id" => $generatedId + [
                    "expression" => "CASE WHEN target_type = 'library_item' THEN source_id_snapshot ELSE NULL END",
                ],
                "external_target_id" => $generatedId + [
                    "expression" => "CASE WHEN target_type = 'external_loan' THEN source_id_snapshot ELSE NULL END",
                ],
            ] : [
                "entry_id" => $id,
                "user_id" => $id,
                "work_id" => $id,
                "preferred_source_type" => ["type" => "varchar(32)", "nullable" => "YES"],
                "preferred_source_id_snapshot" => $nullableId,
                "preferred_source_library_id_snapshot" => $nullableId,
                "item_id" => $nullableId,
                "external_loan_id" => $nullableId,
                "position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
            ],
            $this->tableNames->nextReadingUndo() => [
                "undo_token_hash" => ["type" => "char(64)", "nullable" => "NO", "collation" => "ascii_bin"],
                "user_id" => $id,
                "entry_id" => $id,
                "work_id" => $id,
                "preferred_source_type" => ["type" => "varchar(32)", "nullable" => "YES"],
                "preferred_source_id_snapshot" => $nullableId,
                "preferred_source_library_id_snapshot" => $nullableId,
                "item_id" => $nullableId,
                "external_loan_id" => $nullableId,
                "original_position" => ["type" => "bigint(20) unsigned", "nullable" => "NO"],
                "previous_entry_id" => $nullableId,
                "next_entry_id" => $nullableId,
                "entry_created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "created_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "expires_at" => ["type" => "datetime(6)", "nullable" => "NO"],
            ],
            $this->tableNames->libraryBookTypes() => [
                "library_id" => $id,
                "book_type_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "normalized_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "term_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "seed_key" => $nullableId,
            ],
            $this->tableNames->libraryGenres() => [
                "library_id" => $id,
                "genre_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "normalized_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "term_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "seed_key" => $nullableId,
            ],
            $this->tableNames->librarySubjects() => [
                "library_id" => $id,
                "subject_id" => $id,
                "display_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "normalized_name" => [
                    "type" => "varchar(512)",
                    "nullable" => "NO",
                    "collation" => "utf8mb4_bin",
                ],
                "term_status" => ["type" => "varchar(32)", "nullable" => "NO"],
                "seed_key" => $nullableId,
            ],
            $this->tableNames->libraryCatalogContexts() => [
                "library_id" => $id,
                "work_id" => $id,
                "book_type_id" => $id,
                "context_version" => [
                    "type" => "bigint(20) unsigned",
                    "nullable" => "NO",
                ],
            ],
            $this->tableNames->libraryCatalogContextGenres() => [
                "library_id" => $id,
                "work_id" => $id,
                "genre_id" => $id,
            ],
            $this->tableNames->libraryCatalogContextSubjects() => [
                "library_id" => $id,
                "work_id" => $id,
                "subject_id" => $id,
            ],
            $this->tableNames->libraryActivityEvents() => [
                "event_id" => $id,
                "library_id" => $id,
                "occurred_at" => ["type" => "datetime(6)", "nullable" => "NO"],
                "actor_user_id" => $nullableId,
                "actor_display_name" => ["type" => "longtext", "nullable" => "YES"],
                "event_source" => $id,
                "event_key" => $id,
                "primary_entity_type" => $id,
                "primary_entity_id" => $id,
                "related_entities_json" => ["type" => "longtext", "nullable" => "NO"],
                "changes_json" => ["type" => "longtext", "nullable" => "NO"],
            ],
        ];
    }

    /** @return array<string, array<string, array{unique: bool, columns: list<string>}>> */
    private function expectedIndexes(int $schemaVersion): array
    {
        $membershipIndexes = [
            "PRIMARY" => ["unique" => true, "columns" => ["library_id", "user_id"]],
            "one_active_owner" => ["unique" => true, "columns" => ["active_owner_library_id"]],
        ];

        if ($schemaVersion >= 1007) {
            $membershipIndexes["memberships_by_user"] = [
                "unique" => false,
                "columns" => ["user_id", "library_id"],
            ];
        }

        return [
            $this->tableNames->libraries() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id"]],
            ],
            $this->tableNames->memberships() => $membershipIndexes,
            $this->tableNames->personalLibraryDesignations() => [
                "PRIMARY" => ["unique" => true, "columns" => ["user_id"]],
                "one_personal_user_per_library" => ["unique" => true, "columns" => ["library_id"]],
            ],
            $this->tableNames->works() => [
                "PRIMARY" => ["unique" => true, "columns" => ["work_id"]],
            ],
            $this->tableNames->authors() => [
                "PRIMARY" => ["unique" => true, "columns" => ["author_id"]],
                "authors_by_display_name" => [
                    "unique" => false,
                    "columns" => ["display_name", "author_id"],
                ],
            ],
            $this->tableNames->workContributors() => [
                "PRIMARY" => ["unique" => true, "columns" => ["work_id", "author_id"]],
                "work_contributors_by_position" => [
                    "unique" => true,
                    "columns" => ["work_id", "contributor_position"],
                ],
                "work_contributors_by_author" => [
                    "unique" => false,
                    "columns" => ["author_id", "work_id"],
                ],
            ],
            $this->tableNames->series() => [
                "PRIMARY" => ["unique" => true, "columns" => ["series_id"]],
                "series_by_display_name" => [
                    "unique" => false,
                    "columns" => ["display_name", "series_id"],
                ],
            ],
            $this->tableNames->workSeries() => [
                "PRIMARY" => ["unique" => true, "columns" => ["work_id", "series_id"]],
                "work_series_by_series_order" => [
                    "unique" => false,
                    "columns" => ["series_id", "series_position", "work_id"],
                ],
            ],
            $this->tableNames->workAlternateTitles() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["work_id", "normalized_title"],
                ],
                "alternate_titles_by_title" => [
                    "unique" => false,
                    "columns" => ["normalized_title", "work_id"],
                ],
            ],
            $this->tableNames->workContainments() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["parent_work_id", "contained_work_id"],
                ],
                "work_containments_by_position" => [
                    "unique" => true,
                    "columns" => ["parent_work_id", "contained_position"],
                ],
                "work_containments_by_contained" => [
                    "unique" => false,
                    "columns" => ["contained_work_id", "parent_work_id"],
                ],
            ],
            $this->tableNames->editions() => [
                "PRIMARY" => ["unique" => true, "columns" => ["edition_id"]],
                "editions_by_work" => ["unique" => false, "columns" => ["work_id"]],
                ...($schemaVersion >= 1010 ? [
                    "editions_by_isbn10" => [
                        "unique" => false,
                        "columns" => ["isbn_10", "edition_id"],
                    ],
                    "editions_by_isbn13" => [
                        "unique" => false,
                        "columns" => ["isbn_13", "edition_id"],
                    ],
                ] : []),
            ],
            $this->tableNames->items() => [
                "PRIMARY" => ["unique" => true, "columns" => ["item_id"]],
                "items_by_library" => ["unique" => false, "columns" => ["library_id"]],
                "items_by_edition" => ["unique" => false, "columns" => ["edition_id"]],
                ...($schemaVersion >= 1010 ? [
                    "items_by_library_inventory_number" => [
                        "unique" => true,
                        "columns" => ["library_id", "inventory_number"],
                    ],
                ] : []),
            ] + ($schemaVersion >= 1011 ? [
                "items_by_library_location" => [
                    "unique" => false,
                    "columns" => ["library_id", "location_id", "item_id"],
                ],
            ] : []) + ($schemaVersion >= 1012 ? [
                "items_by_library_identity" => [
                    "unique" => true,
                    "columns" => ["library_id", "item_id"],
                ],
                "items_by_library_status_location" => [
                    "unique" => false,
                    "columns" => ["library_id", "item_status", "location_id", "item_id"],
                ],
            ] : []),
            $this->tableNames->itemArchivePeriods() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id", "item_id", "archive_version"]],
                "one_open_item_archive_period" => ["unique" => true, "columns" => ["library_id", "open_item_id"]],
                "item_archive_periods_by_item_time" => ["unique" => false, "columns" => ["library_id", "item_id", "archived_at", "archive_version"]],
            ],
            $this->tableNames->collections() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id", "collection_id"]],
                "collections_active_name" => ["unique" => true, "columns" => ["library_id", "active_normalized_name"]],
                "collections_active_order" => ["unique" => false, "columns" => ["library_id", "collection_status", "collection_position", "collection_id"]],
            ],
            $this->tableNames->collectionMemberships() => [
                "PRIMARY" => ["unique" => true, "columns" => ["library_id", "membership_id"]],
                "collection_one_active_item" => ["unique" => true, "columns" => ["library_id", "collection_id", "active_item_id"]],
                "collection_memberships_active_order" => ["unique" => false, "columns" => ["library_id", "collection_id", "membership_status", "item_position"]],
                "collection_memberships_by_item" => ["unique" => false, "columns" => ["library_id", "item_id", "membership_status"]],
                "collection_memberships_history" => ["unique" => false, "columns" => ["library_id", "item_id", "end_reason", "ended_at"]],
            ],
            $this->tableNames->locations() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "location_id"],
                ],
                "locations_by_library_name" => [
                    "unique" => false,
                    "columns" => ["library_id", "display_name", "location_id"],
                ],
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
            ] + ($schemaVersion < 1003 ? [] : [
                "reading_rounds_by_user_work_finish" => [
                    "unique" => false,
                    "columns" => [
                        "user_id",
                        "work_id",
                        "round_outcome",
                        "reading_finished_year",
                        "reading_finished_month",
                        "reading_finished_day",
                        "reading_round_id",
                    ],
                ],
            ]),
            $this->tableNames->privateNotes() => [
                "PRIMARY" => ["unique" => true, "columns" => ["private_note_id"]],
                "private_notes_by_user_work" => [
                    "unique" => false,
                    "columns" => ["user_id", "work_id", "updated_at", "private_note_id"],
                ],
                "private_notes_by_user_round" => [
                    "unique" => false,
                    "columns" => ["user_id", "reading_round_id", "updated_at", "private_note_id"],
                ],
                "private_notes_by_user_updated" => [
                    "unique" => false,
                    "columns" => ["user_id", "updated_at", "private_note_id"],
                ],
            ],
            $this->tableNames->ratings() => [
                "PRIMARY" => ["unique" => true, "columns" => ["rating_id"]],
                "one_unlinked_rating" => ["unique" => true, "columns" => ["user_id", "unlinked_work_id"]],
                "one_rating_per_round" => ["unique" => true, "columns" => ["user_id", "reading_round_id"]],
                "ratings_by_user_work" => ["unique" => false, "columns" => ["user_id", "work_id", "updated_at", "rating_id"]],
                "ratings_by_user_round" => ["unique" => false, "columns" => ["user_id", "reading_round_id", "updated_at", "rating_id"]],
                "ratings_by_user_updated" => ["unique" => false, "columns" => ["user_id", "updated_at", "rating_id"]],
                "ratings_by_work" => ["unique" => false, "columns" => ["work_id", "updated_at", "rating_id"]],
            ],
            $this->tableNames->reviews() => [
                "PRIMARY" => ["unique" => true, "columns" => ["review_id"]],
                "one_unlinked_review" => ["unique" => true, "columns" => ["user_id", "unlinked_work_id"]],
                "one_review_per_round" => ["unique" => true, "columns" => ["user_id", "reading_round_id"]],
                "reviews_by_user_work" => ["unique" => false, "columns" => ["user_id", "work_id", "updated_at", "review_id"]],
                "reviews_by_user_round" => ["unique" => false, "columns" => ["user_id", "reading_round_id", "updated_at", "review_id"]],
                "reviews_by_user_updated" => ["unique" => false, "columns" => ["user_id", "updated_at", "review_id"]],
                "reviews_by_work" => ["unique" => false, "columns" => ["work_id", "updated_at", "review_id"]],
            ],
            $this->tableNames->contributionPublications() => [
                "PRIMARY" => ["unique" => true, "columns" => ["publication_id"]],
                "rating_library_history" => ["unique" => true, "columns" => ["rating_id", "library_id"]],
                "review_library_history" => ["unique" => true, "columns" => ["review_id", "library_id"]],
                "one_current_rating_publication" => ["unique" => true, "columns" => ["active_rating_id"]],
                "one_current_review_publication" => ["unique" => true, "columns" => ["active_review_id"]],
                "publications_by_library_state" => ["unique" => false, "columns" => ["library_id", "author_status", "moderation_status", "updated_at", "publication_id"]],
                "publications_by_rating" => ["unique" => false, "columns" => ["rating_id"]],
                "publications_by_review" => ["unique" => false, "columns" => ["review_id"]],
            ],
            $this->tableNames->nextReadingLists() => [
                "PRIMARY" => ["unique" => true, "columns" => ["user_id"]],
            ],
            $this->tableNames->nextReadingEntries() => $schemaVersion < 1008 ? [
                "PRIMARY" => ["unique" => true, "columns" => ["entry_id"]],
                "next_reading_user_position" => ["unique" => true, "columns" => ["user_id", "position"]],
                "one_next_reading_work_target" => ["unique" => true, "columns" => ["user_id", "work_target_id"]],
                "one_next_reading_item_target" => ["unique" => true, "columns" => ["user_id", "item_target_id"]],
                "one_next_reading_external_target" => ["unique" => true, "columns" => ["user_id", "external_target_id"]],
                "next_reading_by_user_order" => ["unique" => false, "columns" => ["user_id", "position", "entry_id"]],
            ] : [
                "PRIMARY" => ["unique" => true, "columns" => ["entry_id"]],
                "next_reading_user_position" => ["unique" => true, "columns" => ["user_id", "position"]],
                "next_reading_by_user_order" => ["unique" => false, "columns" => ["user_id", "position", "entry_id"]],
                "next_reading_by_user_work_order" => ["unique" => false, "columns" => ["user_id", "work_id", "position", "entry_id"]],
                "next_reading_by_user_work_item" => ["unique" => false, "columns" => ["user_id", "work_id", "item_id", "position", "entry_id"]],
                "next_reading_by_user_work_external" => ["unique" => false, "columns" => ["user_id", "work_id", "external_loan_id", "position", "entry_id"]],
            ],
            $this->tableNames->nextReadingUndo() => [
                "PRIMARY" => ["unique" => true, "columns" => ["undo_token_hash"]],
                "next_reading_undo_by_user_expiry" => ["unique" => false, "columns" => ["user_id", "expires_at"]],
            ],
            $this->tableNames->libraryBookTypes() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "book_type_id"],
                ],
                "book_types_by_normalized_name" => [
                    "unique" => true,
                    "columns" => ["library_id", "normalized_name"],
                ],
                "book_types_by_seed_key" => [
                    "unique" => true,
                    "columns" => ["library_id", "seed_key"],
                ],
            ],
            $this->tableNames->libraryGenres() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "genre_id"],
                ],
                "genres_by_normalized_name" => [
                    "unique" => true,
                    "columns" => ["library_id", "normalized_name"],
                ],
                "genres_by_seed_key" => [
                    "unique" => true,
                    "columns" => ["library_id", "seed_key"],
                ],
            ],
            $this->tableNames->librarySubjects() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "subject_id"],
                ],
                "subjects_by_normalized_name" => [
                    "unique" => true,
                    "columns" => ["library_id", "normalized_name"],
                ],
                "subjects_by_seed_key" => [
                    "unique" => true,
                    "columns" => ["library_id", "seed_key"],
                ],
            ],
            $this->tableNames->libraryCatalogContexts() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "work_id"],
                ],
                "catalog_contexts_by_work" => [
                    "unique" => false,
                    "columns" => ["work_id"],
                ],
                "catalog_contexts_by_book_type" => [
                    "unique" => false,
                    "columns" => ["library_id", "book_type_id"],
                ],
            ],
            $this->tableNames->libraryCatalogContextGenres() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "work_id", "genre_id"],
                ],
                "context_genres_by_genre" => [
                    "unique" => false,
                    "columns" => ["library_id", "genre_id"],
                ],
            ],
            $this->tableNames->libraryCatalogContextSubjects() => [
                "PRIMARY" => [
                    "unique" => true,
                    "columns" => ["library_id", "work_id", "subject_id"],
                ],
                "context_subjects_by_subject" => [
                    "unique" => false,
                    "columns" => ["library_id", "subject_id"],
                ],
            ],
            $this->tableNames->libraryActivityEvents() => [
                "PRIMARY" => ["unique" => true, "columns" => ["event_id"]],
                "activity_events_by_library_time" => [
                    "unique" => false,
                    "columns" => ["library_id", "occurred_at", "event_id"],
                ],
            ],
        ];
    }

    /** @return array<string, list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, update: string, delete: string}>> */
    private function expectedForeignKeys(int $schemaVersion): array
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
        $setNull = static fn (
            array $columns,
            string $referencedTable,
            array $referencedColumns
        ): array => [
            "columns" => $columns,
            "referenced_table" => $referencedTable,
            "referenced_columns" => $referencedColumns,
            "update" => "RESTRICT",
            "delete" => "SET NULL",
        ];
        $cascade = static fn (
            array $columns,
            string $referencedTable,
            array $referencedColumns
        ): array => [
            "columns" => $columns,
            "referenced_table" => $referencedTable,
            "referenced_columns" => $referencedColumns,
            "update" => "RESTRICT",
            "delete" => "CASCADE",
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
            $this->tableNames->workContributors() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $restrict(["author_id"], $this->tableNames->authors(), ["author_id"]),
            ],
            $this->tableNames->workSeries() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $restrict(["series_id"], $this->tableNames->series(), ["series_id"]),
            ],
            $this->tableNames->workAlternateTitles() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
            ],
            $this->tableNames->workContainments() => [
                $restrict(
                    ["parent_work_id"],
                    $this->tableNames->works(),
                    ["work_id"]
                ),
                $restrict(
                    ["contained_work_id"],
                    $this->tableNames->works(),
                    ["work_id"]
                ),
            ],
            $this->tableNames->editions() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
            ],
            $this->tableNames->items() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
                $restrict(["edition_id"], $this->tableNames->editions(), ["edition_id"]),
                ...($schemaVersion >= 1011 ? [
                    $restrict(
                        ["library_id", "location_id"],
                        $this->tableNames->locations(),
                        ["library_id", "location_id"]
                    ),
                ] : []),
            ],
            $this->tableNames->itemArchivePeriods() => [
                $restrict(
                    ["library_id", "item_id"],
                    $this->tableNames->items(),
                    ["library_id", "item_id"]
                ),
            ],
            $this->tableNames->collections() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
            ],
            $this->tableNames->collectionMemberships() => [
                $restrict(
                    ["library_id", "collection_id"],
                    $this->tableNames->collections(),
                    ["library_id", "collection_id"]
                ),
                $restrict(
                    ["library_id", "item_id"],
                    $this->tableNames->items(),
                    ["library_id", "item_id"]
                ),
            ],
            $this->tableNames->locations() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
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
            $this->tableNames->privateNotes() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $setNull(
                    ["reading_round_id"],
                    $this->tableNames->readingRounds(),
                    ["reading_round_id"]
                ),
            ],
            $this->tableNames->ratings() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $restrict(["reading_round_id"], $this->tableNames->readingRounds(), ["reading_round_id"]),
            ],
            $this->tableNames->reviews() => [
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $restrict(["reading_round_id"], $this->tableNames->readingRounds(), ["reading_round_id"]),
            ],
            $this->tableNames->contributionPublications() => [
                $restrict(["library_id"], $this->tableNames->libraries(), ["library_id"]),
                $cascade(["rating_id"], $this->tableNames->ratings(), ["rating_id"]),
                $cascade(["review_id"], $this->tableNames->reviews(), ["review_id"]),
            ],
            $this->tableNames->nextReadingEntries() => [
                $cascade(["user_id"], $this->tableNames->nextReadingLists(), ["user_id"]),
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $setNull(["item_id"], $this->tableNames->items(), ["item_id"]),
                $setNull(["external_loan_id"], $this->tableNames->externalLoans(), ["external_loan_id"]),
            ],
            $this->tableNames->nextReadingUndo() => [
                $cascade(["user_id"], $this->tableNames->nextReadingLists(), ["user_id"]),
                $restrict(["work_id"], $this->tableNames->works(), ["work_id"]),
                $setNull(["item_id"], $this->tableNames->items(), ["item_id"]),
                $setNull(["external_loan_id"], $this->tableNames->externalLoans(), ["external_loan_id"]),
            ],
            $this->tableNames->libraryBookTypes() => [
                $restrict(
                    ["library_id"],
                    $this->tableNames->libraries(),
                    ["library_id"]
                ),
            ],
            $this->tableNames->libraryGenres() => [
                $restrict(
                    ["library_id"],
                    $this->tableNames->libraries(),
                    ["library_id"]
                ),
            ],
            $this->tableNames->librarySubjects() => [
                $restrict(
                    ["library_id"],
                    $this->tableNames->libraries(),
                    ["library_id"]
                ),
            ],
            $this->tableNames->libraryCatalogContexts() => [
                $restrict(
                    ["library_id"],
                    $this->tableNames->libraries(),
                    ["library_id"]
                ),
                $restrict(
                    ["work_id"],
                    $this->tableNames->works(),
                    ["work_id"]
                ),
                $restrict(
                    ["library_id", "book_type_id"],
                    $this->tableNames->libraryBookTypes(),
                    ["library_id", "book_type_id"]
                ),
            ],
            $this->tableNames->libraryCatalogContextGenres() => [
                $restrict(
                    ["library_id", "work_id"],
                    $this->tableNames->libraryCatalogContexts(),
                    ["library_id", "work_id"]
                ),
                $restrict(
                    ["library_id", "genre_id"],
                    $this->tableNames->libraryGenres(),
                    ["library_id", "genre_id"]
                ),
            ],
            $this->tableNames->libraryCatalogContextSubjects() => [
                $restrict(
                    ["library_id", "work_id"],
                    $this->tableNames->libraryCatalogContexts(),
                    ["library_id", "work_id"]
                ),
                $restrict(
                    ["library_id", "subject_id"],
                    $this->tableNames->librarySubjects(),
                    ["library_id", "subject_id"]
                ),
            ],
            $this->tableNames->libraryActivityEvents() => [
                $restrict(
                    ["library_id"],
                    $this->tableNames->libraries(),
                    ["library_id"]
                ),
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function expectedChecks(int $schemaVersion): array
    {
        return [
            $this->tableNames->libraries() => [
                "library_type = 'private_library'",
                "library_status = 'active'",
                ...($schemaVersion >= 1007
                    ? ["CHAR_LENGTH(TRIM(library_name)) > 0"]
                    : []),
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
            $this->tableNames->authors() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
            ],
            $this->tableNames->workContributors() => [
                "contributor_role IN ('author', 'co_author')",
                "contributor_position >= 1",
            ],
            $this->tableNames->series() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
            ],
            $this->tableNames->workAlternateTitles() => [
                "CHAR_LENGTH(TRIM(alternate_title)) > 0",
                "CHAR_LENGTH(TRIM(normalized_title)) > 0",
            ],
            $this->tableNames->workContainments() => [
                "parent_work_id <> contained_work_id",
                "contained_position >= 1",
            ],
            $this->tableNames->editions() => $schemaVersion >= 1010 ? [
                "explicitly_no_isbn IN (0, 1)",
                "explicitly_no_isbn = 0 OR isbn_10 IS NULL AND isbn_13 IS NULL",
                "isbn_10 IS NULL OR isbn_10 REGEXP '^[0-9]{9}[0-9X]$'",
                "isbn_13 IS NULL OR isbn_13 REGEXP '^[0-9]{13}$'",
            ] : [],
            $this->tableNames->items() => [
                $schemaVersion >= 1012
                    ? "item_status IN ('active', 'archived')"
                    : "item_status = 'active'",
                ...($schemaVersion >= 1010
                    ? ["inventory_number IS NULL OR CHAR_LENGTH(TRIM(inventory_number)) > 0"]
                    : []),
                ...($schemaVersion >= 1012 ? ["item_version >= 1"] : []),
            ],
            $this->tableNames->itemArchivePeriods() => [
                "archive_version >= 2",
                "archive_reason IN ('sold', 'given_away', 'donated', 'lost', 'damaged_discarded', 'not_returned')",
                "restore_version IS NULL = (restored_at IS NULL)",
                "restore_version IS NULL OR restore_version > archive_version",
                "restored_at IS NULL OR restored_at >= archived_at",
            ],
            $this->tableNames->collections() => [
                "CHAR_LENGTH(TRIM(collection_name)) > 0",
                "CHAR_LENGTH(TRIM(normalized_name)) > 0",
                "collection_status IN ('active', 'archived')",
                "collection_position >= 1",
                "collection_version >= 1",
                "updated_at >= created_at",
            ],
            $this->tableNames->collectionMemberships() => [
                "membership_status IN ('active', 'inactive')",
                "item_position >= 1",
                "ended_at IS NULL = (end_reason IS NULL)",
                "membership_status = 'active' AND ended_at IS NULL OR membership_status = 'inactive' AND ended_at IS NOT NULL",
                "end_reason IS NULL OR end_reason IN ('removed', 'item_archived')",
                "ended_at IS NULL OR ended_at >= added_at",
            ],
            $this->tableNames->locations() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
            ],
            $this->tableNames->externalLoans() => [
                "CHAR_LENGTH(TRIM(user_id)) > 0",
                "loan_status = 'active'",
            ],
            $this->tableNames->readingRounds() => $schemaVersion < 1003
                ? [
                    "round_status = 'active'",
                    "item_id IS NOT NULL AND external_loan_id IS NULL OR "
                        . "item_id IS NULL AND external_loan_id IS NOT NULL",
                ]
                : self::readingRound1003Checks(),
            $this->tableNames->privateNotes() => [
                "note_version >= 1",
                "updated_at >= created_at",
            ],
            $this->tableNames->ratings() => [
                "rating_half_units BETWEEN 2 AND 10",
                "rating_version >= 1",
                "updated_at >= created_at",
            ],
            $this->tableNames->reviews() => [
                "CHAR_LENGTH(review_content) <= 5000",
                "review_version >= 1",
                "updated_at >= created_at",
            ],
            $this->tableNames->contributionPublications() => [
                "rating_id IS NOT NULL <> (review_id IS NOT NULL)",
                "author_status IN ('active', 'withdrawn')",
                "moderation_status IN ('visible', 'hidden', 'removed')",
                "moderation_status = 'visible' AND moderation_reason IS NULL AND moderator_user_id IS NULL AND moderated_at IS NULL OR moderation_status IN ('hidden', 'removed') AND moderation_reason IS NOT NULL AND CHAR_LENGTH(TRIM(moderation_reason)) > 0 AND moderator_user_id IS NOT NULL AND moderated_at IS NOT NULL",
                "publication_version >= 1",
                "updated_at >= published_at",
            ],
            $this->tableNames->nextReadingLists() => [
                "CHAR_LENGTH(TRIM(user_id)) > 0",
                "list_version >= 1",
                "updated_at >= created_at",
            ],
            $this->tableNames->nextReadingEntries() => $schemaVersion < 1008 ? [
                "target_type IN ('work', 'library_item', 'external_loan')",
                "target_type = 'work' AND source_id_snapshot IS NULL AND source_library_id_snapshot IS NULL OR target_type = 'library_item' AND source_id_snapshot IS NOT NULL AND source_library_id_snapshot IS NOT NULL OR target_type = 'external_loan' AND source_id_snapshot IS NOT NULL AND source_library_id_snapshot IS NULL",
                "position >= 1",
            ] : [
                "preferred_source_type IS NULL OR preferred_source_type IN ('library_item', 'external_loan')",
                "preferred_source_type IS NULL AND preferred_source_id_snapshot IS NULL AND preferred_source_library_id_snapshot IS NULL OR preferred_source_type = 'library_item' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NOT NULL OR preferred_source_type = 'external_loan' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NULL",
                "position >= 1",
            ],
            $this->tableNames->nextReadingUndo() => [
                "CHAR_LENGTH(undo_token_hash) = 64",
                "preferred_source_type IS NULL OR preferred_source_type IN ('library_item', 'external_loan')",
                "preferred_source_type IS NULL AND preferred_source_id_snapshot IS NULL AND preferred_source_library_id_snapshot IS NULL OR preferred_source_type = 'library_item' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NOT NULL OR preferred_source_type = 'external_loan' AND preferred_source_id_snapshot IS NOT NULL AND preferred_source_library_id_snapshot IS NULL",
                "original_position >= 1",
                "expires_at > created_at",
            ],
            $this->tableNames->libraryBookTypes() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
                "CHAR_LENGTH(TRIM(normalized_name)) > 0",
                "term_status IN ('active', 'inactive')",
            ],
            $this->tableNames->libraryGenres() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
                "CHAR_LENGTH(TRIM(normalized_name)) > 0",
                "term_status IN ('active', 'inactive')",
            ],
            $this->tableNames->librarySubjects() => [
                "CHAR_LENGTH(TRIM(display_name)) > 0",
                "CHAR_LENGTH(TRIM(normalized_name)) > 0",
                "term_status IN ('active', 'inactive')",
            ],
            $this->tableNames->libraryCatalogContexts() => [
                "context_version >= 1",
            ],
            $this->tableNames->libraryActivityEvents() => [
                "actor_user_id IS NULL OR actor_display_name IS NOT NULL",
                "actor_display_name IS NULL OR "
                    . "CHAR_LENGTH(TRIM(actor_display_name)) > 0",
                "JSON_VALID(related_entities_json)",
                "JSON_VALID(changes_json)",
            ],
        ];
    }

    /** @return list<string> */
    private static function readingRound1003Checks(): array
    {
        return [
            "round_outcome IS NULL OR round_outcome IN ('completed', 'stopped')",
            "provenance IN ('legacy_source_started', 'source_started', "
                . "'historical_manual')",
            "item_id IS NULL OR external_loan_id IS NULL",
            "provenance = 'legacy_source_started' AND started_at IS NOT NULL "
                . "AND reading_started_year IS NULL AND reading_started_month IS NULL "
                . "AND reading_started_day IS NULL OR provenance = 'source_started' "
                . "AND started_at IS NULL AND reading_started_year IS NOT NULL "
                . "AND reading_started_month IS NOT NULL AND reading_started_day IS NOT NULL "
                . "OR provenance = 'historical_manual' AND started_at IS NULL "
                . "AND round_outcome IS NOT NULL",
            "round_outcome IS NULL AND reading_finished_year IS NULL "
                . "AND reading_finished_month IS NULL AND reading_finished_day IS NULL "
                . "OR round_outcome IS NOT NULL AND reading_finished_year IS NOT NULL",
            "(reading_started_year IS NULL AND reading_started_month IS NULL "
                . "AND reading_started_day IS NULL OR reading_started_year BETWEEN 1000 AND 9999 "
                . "AND (reading_started_month IS NULL AND reading_started_day IS NULL "
                . "OR reading_started_month BETWEEN 1 AND 12 "
                . "AND (reading_started_day IS NULL OR reading_started_day BETWEEN 1 AND "
                . "DAYOFMONTH(LAST_DAY(CONCAT(reading_started_year, '-', reading_started_month, '-01')))))) "
                . "AND (reading_finished_year IS NULL AND reading_finished_month IS NULL "
                . "AND reading_finished_day IS NULL OR reading_finished_year BETWEEN 1000 AND 9999 "
                . "AND (reading_finished_month IS NULL AND reading_finished_day IS NULL "
                . "OR reading_finished_month BETWEEN 1 AND 12 "
                . "AND (reading_finished_day IS NULL OR reading_finished_day BETWEEN 1 AND "
                . "DAYOFMONTH(LAST_DAY(CONCAT(reading_finished_year, '-', reading_finished_month, '-01'))))))",
            "reading_started_year IS NULL OR reading_started_year * 10000 "
                . "+ COALESCE(reading_started_month, 1) * 100 "
                . "+ COALESCE(reading_started_day, 1) <= reading_finished_year * 10000 "
                . "+ COALESCE(reading_finished_month, 12) * 100 "
                . "+ COALESCE(reading_finished_day, DAYOFMONTH(LAST_DAY(CONCAT(reading_finished_year, '-', "
                . "COALESCE(reading_finished_month, 12), '-01'))))",
            "(round_outcome IS NULL AND ended_at IS NULL OR round_outcome IS NOT NULL "
                . "AND ended_at IS NOT NULL) AND (provenance = 'legacy_source_started' "
                . "OR created_at IS NOT NULL AND updated_at IS NOT NULL) "
                . "AND (created_at IS NULL OR updated_at IS NULL OR updated_at >= created_at)",
            "round_version >= 1",
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
