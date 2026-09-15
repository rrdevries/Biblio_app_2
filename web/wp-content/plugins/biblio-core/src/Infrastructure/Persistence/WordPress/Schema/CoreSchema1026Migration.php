<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1026Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $health;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->health = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1025; }
    public function targetVersion(): int { return 1026; }

    public function assertPrecondition(): void
    {
        $target = $this->health->inspectForVersion(1026);
        if ($target->isHealthy()) {
            return;
        }

        $source = $this->health->inspectForVersion(1025);
        if (
            $source->isHealthy()
            && $this->hasNamedCheck(
                "reading_rounds_provenance",
                "provenance IN ('legacy_source_started', 'source_started', "
                    . "'historical_manual')"
            )
            && $this->hasNamedCheck(
                "reading_rounds_start_shape",
                $this->sourceStartShape()
            )
        ) {
            return;
        }

        throw new CoreSchemaMigrationException(
            "Schema 1026 requires an exact healthy 1025 source or an exact completed 1026 retry state: "
                . $source->summary() . "; " . $target->summary()
        );
    }

    public function migrate(): void
    {
        if ($this->health->inspectForVersion(1026)->isHealthy()) {
            return;
        }

        $table = $this->tables->readingRounds();
        $provenance = "provenance IN ('legacy_source_started', 'source_started', "
            . "'historical_manual', 'migration_imported')";
        $startShape = "provenance = 'legacy_source_started' AND started_at IS NOT NULL "
            . "AND reading_started_year IS NULL AND reading_started_month IS NULL "
            . "AND reading_started_day IS NULL OR provenance = 'source_started' "
            . "AND started_at IS NULL AND reading_started_year IS NOT NULL "
            . "AND reading_started_month IS NOT NULL AND reading_started_day IS NOT NULL "
            . "OR provenance = 'historical_manual' AND started_at IS NULL "
            . "AND round_outcome IS NOT NULL OR provenance = 'migration_imported' "
            . "AND started_at IS NULL AND (round_outcome IS NOT NULL "
            . "OR reading_started_year IS NOT NULL AND reading_started_month IS NOT NULL "
            . "AND reading_started_day IS NOT NULL)";
        $sql = "ALTER TABLE `{$table}` "
            . "DROP CONSTRAINT `reading_rounds_provenance`, "
            . "DROP CONSTRAINT `reading_rounds_start_shape`, "
            . "ADD CONSTRAINT `reading_rounds_provenance` CHECK ({$provenance}), "
            . "ADD CONSTRAINT `reading_rounds_start_shape` CHECK ({$startShape})";

        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not add ReadingRound migration provenance: "
                    . $this->database->last_error
            );
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->health->inspectForVersion(1026);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function sourceStartShape(): string
    {
        return "provenance = 'legacy_source_started' AND started_at IS NOT NULL "
            . "AND reading_started_year IS NULL AND reading_started_month IS NULL "
            . "AND reading_started_day IS NULL OR provenance = 'source_started' "
            . "AND started_at IS NULL AND reading_started_year IS NOT NULL "
            . "AND reading_started_month IS NOT NULL AND reading_started_day IS NOT NULL "
            . "OR provenance = 'historical_manual' AND started_at IS NULL "
            . "AND round_outcome IS NOT NULL";
    }

    private function hasNamedCheck(string $name, string $expected): bool
    {
        $actual = $this->database->get_var($this->database->prepare(
            "SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c "
                . "INNER JOIN information_schema.TABLE_CONSTRAINTS t "
                . "ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA "
                . "AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME "
                . "WHERE t.CONSTRAINT_SCHEMA=%s AND t.TABLE_NAME=%s "
                . "AND t.CONSTRAINT_NAME=%s AND t.CONSTRAINT_TYPE='CHECK'",
            DB_NAME,
            $this->tables->readingRounds(),
            $name
        ));
        if (!is_string($actual)) {
            return false;
        }

        $normalize = static fn (string $value): string => strtolower(
            preg_replace('/[\s`()]+/', '', $value) ?? $value
        );

        return $normalize($actual) === $normalize($expected);
    }
}
