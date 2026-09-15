<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{
    CoreSchema1026Migration,
    CoreSchemaHealthChecker,
    CoreSchemaMigrationException,
    CoreSchemaMigrator
};

final class Schema1026ReadingRoundMigrationProvenanceTest extends
    PersistenceIntegrationTestCase
{
    public function testUpgradeAddsExactProvenanceAndIsRetrySafe(): void
    {
        $this->setHistoricalSchemaVersion(1025);
        $health = new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        );
        self::assertTrue(
            $health->inspectForVersion(1025)->isHealthy(),
            $health->inspectForVersion(1025)->summary()
        );
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "schema-1026-work",
            "work_title" => "Preserved Work",
        ]);
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => "schema-1026-round",
            "user_id" => "schema-1026-user",
            "work_id" => "schema-1026-work",
            "round_outcome" => "completed",
            "provenance" => "historical_manual",
            "reading_finished_year" => 2020,
            "created_at" => "2026-09-15 12:00:00.000000",
            "updated_at" => "2026-09-15 12:00:00.000000",
            "ended_at" => "2026-09-15 12:00:00.000000",
            "round_version" => 1,
        ]);

        $migration = new CoreSchema1026Migration(
            $this->database,
            $this->tableNames
        );
        self::assertSame(1025, $migration->sourceVersion());
        self::assertSame(1026, $migration->targetVersion());
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertTrue(
            $health->inspectForVersion(1026)->isHealthy(),
            $health->inspectForVersion(1026)->summary()
        );
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE reading_round_id='schema-1026-round' "
                . "AND provenance='historical_manual'"
        ));

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1026", false);
    }

    public function testNamedConstraintDriftAndBlockingLegacyCheckFailClosed(): void
    {
        $table = $this->tableNames->readingRounds();
        $health = new CoreSchemaHealthChecker($this->database, $this->tableNames);
        self::assertNotFalse($this->database->query(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `reading_rounds_legacy_blocker` "
                . "CHECK (provenance IN ('legacy_source_started', "
                . "'source_started', 'historical_manual'))"
        ));
        try {
            self::assertFalse($health->inspectForVersion(1026)->isHealthy());
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` DROP CONSTRAINT `reading_rounds_legacy_blocker`"
            );
        }

        $this->setHistoricalSchemaVersion(1025);
        self::assertNotFalse($this->database->query(
            "ALTER TABLE `{$table}` DROP CONSTRAINT `reading_rounds_provenance`, "
                . "ADD CONSTRAINT `unexpected_provenance_name` CHECK ("
                . "provenance IN ('legacy_source_started', 'source_started', "
                . "'historical_manual'))"
        ));
        try {
            (new CoreSchema1026Migration(
                $this->database,
                $this->tableNames
            ))->assertPrecondition();
            self::fail("Renamed source CHECK was accepted by name-dependent DDL.");
        } catch (CoreSchemaMigrationException $failure) {
            self::assertStringContainsString(
                "exact healthy 1025 source",
                $failure->getMessage()
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` DROP CONSTRAINT `unexpected_provenance_name`, "
                    . "ADD CONSTRAINT `reading_rounds_provenance` CHECK ("
                    . "provenance IN ('legacy_source_started', 'source_started', "
                    . "'historical_manual'))"
            );
            $migration = new CoreSchema1026Migration(
                $this->database,
                $this->tableNames
            );
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPostcondition();
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1026", false);
        }
    }
}
