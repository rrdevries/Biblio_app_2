<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1003Migration implements CoreSchemaMigration
{
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

    public function sourceVersion(): int
    {
        return 1002;
    }

    public function targetVersion(): int
    {
        return 1003;
    }

    public function assertPrecondition(): void
    {
        $table = $this->tableNames->readingRounds();

        if (
            $this->columnExists($table, "round_status")
            && !$this->columnExists($table, "round_outcome")
        ) {
            $health = $this->healthChecker->inspectForVersion(1002);

            if (!$health->isHealthy()) {
                throw new CoreSchemaHealthException($health);
            }

            return;
        }

        foreach ([
            "reading_round_id",
            "user_id",
            "work_id",
            "started_at",
            "round_outcome",
            "provenance",
            "round_version",
        ] as $requiredColumn) {
            if (!$this->columnExists($table, $requiredColumn)) {
                throw new CoreSchemaMigrationException(
                    "Schema 1003 retry found an unknown Reading Round state: "
                    . "missing {$requiredColumn}."
                );
            }
        }
    }

    public function migrate(): void
    {
        $table = $this->tableNames->readingRounds();
        $this->addOrdinaryColumns($table);
        $this->execute(
            "UPDATE `{$table}` SET provenance = COALESCE(provenance, "
            . "'legacy_source_started'), round_version = COALESCE(round_version, 1), "
            . "round_outcome = NULL WHERE provenance IS NULL OR round_version IS NULL"
        );
        $this->execute(
            "ALTER TABLE `{$table}` MODIFY started_at DATETIME(6) NULL, "
            . "MODIFY provenance VARCHAR(32) NOT NULL, "
            . "MODIFY round_version BIGINT UNSIGNED NOT NULL"
        );

        if ($this->columnExists($table, "round_status")) {
            $this->dropIndexIfExists($table, "one_active_item_round_per_user");
            $this->dropIndexIfExists($table, "one_active_external_round_per_user");
            $this->dropColumnIfExists($table, "active_item_user_id");
            $this->dropColumnIfExists($table, "active_external_loan_user_id");
            $this->dropConstraintIfExists($table, "reading_rounds_status_active");
            $this->dropConstraintIfExists($table, "reading_rounds_source_xor");
            $this->dropColumnIfExists($table, "round_status");
        }

        if (!$this->columnExists($table, "active_item_user_id")) {
            $this->execute(
                "ALTER TABLE `{$table}` ADD active_item_user_id VARCHAR(191) "
                . "CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS ("
                . "CASE WHEN round_outcome IS NULL AND item_id IS NOT NULL "
                . "THEN user_id ELSE NULL END) STORED"
            );
        }

        if (!$this->columnExists($table, "active_external_loan_user_id")) {
            $this->execute(
                "ALTER TABLE `{$table}` ADD active_external_loan_user_id VARCHAR(191) "
                . "CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS ("
                . "CASE WHEN round_outcome IS NULL AND external_loan_id IS NOT NULL "
                . "THEN user_id ELSE NULL END) STORED"
            );
        }

        $this->addIndexIfMissing(
            $table,
            "one_active_item_round_per_user",
            "UNIQUE (active_item_user_id, item_id)"
        );
        $this->addIndexIfMissing(
            $table,
            "one_active_external_round_per_user",
            "UNIQUE (active_external_loan_user_id, external_loan_id)"
        );
        $this->addIndexIfMissing(
            $table,
            "reading_rounds_by_user_work_finish",
            "(user_id, work_id, round_outcome, reading_finished_year, "
                . "reading_finished_month, reading_finished_day, reading_round_id)"
        );

        foreach (self::checks() as $name => $expression) {
            if (!$this->constraintExists($table, $name)) {
                $this->execute(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` "
                    . "CHECK ({$expression})"
                );
            }
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1003);

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function addOrdinaryColumns(string $table): void
    {
        $columns = [
            "round_outcome" => "VARCHAR(16) NULL",
            "provenance" => "VARCHAR(32) NULL",
            "reading_started_year" => "SMALLINT UNSIGNED NULL",
            "reading_started_month" => "TINYINT UNSIGNED NULL",
            "reading_started_day" => "TINYINT UNSIGNED NULL",
            "reading_finished_year" => "SMALLINT UNSIGNED NULL",
            "reading_finished_month" => "TINYINT UNSIGNED NULL",
            "reading_finished_day" => "TINYINT UNSIGNED NULL",
            "created_at" => "DATETIME(6) NULL",
            "updated_at" => "DATETIME(6) NULL",
            "ended_at" => "DATETIME(6) NULL",
            "round_version" => "BIGINT UNSIGNED NULL",
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->columnExists($table, $name)) {
                $this->execute(
                    "ALTER TABLE `{$table}` ADD `{$name}` {$definition}"
                );
            }
        }
    }

    /** @return array<string, string> */
    private static function checks(): array
    {
        return [
            "reading_rounds_outcome" =>
                "round_outcome IS NULL OR round_outcome IN ('completed', 'stopped')",
            "reading_rounds_provenance" =>
                "provenance IN ('legacy_source_started', 'source_started', 'historical_manual')",
            "reading_rounds_source_shape" =>
                "NOT (item_id IS NOT NULL AND external_loan_id IS NOT NULL)",
            "reading_rounds_start_shape" =>
                "provenance = 'legacy_source_started' AND started_at IS NOT NULL "
                . "AND reading_started_year IS NULL AND reading_started_month IS NULL "
                . "AND reading_started_day IS NULL OR provenance = 'source_started' "
                . "AND started_at IS NULL AND reading_started_year IS NOT NULL "
                . "AND reading_started_month IS NOT NULL AND reading_started_day IS NOT NULL "
                . "OR provenance = 'historical_manual' AND started_at IS NULL "
                . "AND round_outcome IS NOT NULL",
            "reading_rounds_finish_shape" =>
                "round_outcome IS NULL AND reading_finished_year IS NULL "
                . "AND reading_finished_month IS NULL AND reading_finished_day IS NULL "
                . "OR round_outcome IS NOT NULL AND reading_finished_year IS NOT NULL",
            "reading_rounds_calendar_dates" =>
                "(reading_started_year IS NULL AND reading_started_month IS NULL "
                . "AND reading_started_day IS NULL OR reading_started_year BETWEEN 1000 AND 9999 "
                . "AND (reading_started_month IS NULL AND reading_started_day IS NULL "
                . "OR reading_started_month BETWEEN 1 AND 12 "
                . "AND (reading_started_day IS NULL OR reading_started_day BETWEEN 1 AND "
                . "DAY(LAST_DAY(CONCAT(reading_started_year, '-', reading_started_month, '-01')))))) "
                . "AND (reading_finished_year IS NULL AND reading_finished_month IS NULL "
                . "AND reading_finished_day IS NULL OR reading_finished_year BETWEEN 1000 AND 9999 "
                . "AND (reading_finished_month IS NULL AND reading_finished_day IS NULL "
                . "OR reading_finished_month BETWEEN 1 AND 12 "
                . "AND (reading_finished_day IS NULL OR reading_finished_day BETWEEN 1 AND "
                . "DAY(LAST_DAY(CONCAT(reading_finished_year, '-', reading_finished_month, '-01'))))))",
            "reading_rounds_period_possible" =>
                "reading_started_year IS NULL OR reading_started_year * 10000 "
                . "+ COALESCE(reading_started_month, 1) * 100 "
                . "+ COALESCE(reading_started_day, 1) <= reading_finished_year * 10000 "
                . "+ COALESCE(reading_finished_month, 12) * 100 "
                . "+ COALESCE(reading_finished_day, DAY(LAST_DAY(CONCAT(reading_finished_year, '-', "
                . "COALESCE(reading_finished_month, 12), '-01'))))",
            "reading_rounds_technical_time" =>
                "(round_outcome IS NULL AND ended_at IS NULL OR round_outcome IS NOT NULL "
                . "AND ended_at IS NOT NULL) AND (provenance = 'legacy_source_started' "
                . "OR created_at IS NOT NULL AND updated_at IS NOT NULL) "
                . "AND (created_at IS NULL OR updated_at IS NULL OR updated_at >= created_at)",
            "reading_rounds_version_positive" => "round_version >= 1",
        ];
    }

    private function addIndexIfMissing(
        string $table,
        string $name,
        string $definition
    ): void {
        if (!$this->indexExists($table, $name)) {
            $clause = str_starts_with($definition, "UNIQUE ")
                ? "ADD UNIQUE INDEX `{$name}` " . substr($definition, 7)
                : "ADD INDEX `{$name}` {$definition}";
            $this->execute(
                "ALTER TABLE `{$table}` {$clause}"
            );
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            $this->execute("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
        }
    }

    private function dropColumnIfExists(string $table, string $name): void
    {
        if ($this->columnExists($table, $name)) {
            $this->execute("ALTER TABLE `{$table}` DROP COLUMN `{$name}`");
        }
    }

    private function dropConstraintIfExists(string $table, string $name): void
    {
        if ($this->constraintExists($table, $name)) {
            $this->execute("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s "
            . "AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s "
            . "AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $index
        )) > 0;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $table,
            $constraint
        )) === 1;
    }

    private function execute(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not migrate Reading Rounds to schema 1003: "
                . $this->database->last_error
            );
        }
    }
}
