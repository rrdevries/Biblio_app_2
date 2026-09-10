<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1019Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1019PersonalReadingTruthTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->setHistoricalSchemaVersion(1018);
        (new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        ))->migrate();

        parent::tearDown();
    }

    public function testMigrationIsAdditiveHealthyAndRetrySafe(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->setHistoricalSchemaVersion(1018);
        $migration = new CoreSchema1019Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1018, $migration->sourceVersion());
        self::assertSame(1019, $migration->targetVersion());
        self::assertCount(2, $this->tableNames->schema1019Additions());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1019)->isHealthy());
    }

    public function testPartialUnknownStateFailsClosed(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->setHistoricalSchemaVersion(1018);
        $truths = $this->tableNames->personalReadingTruths();
        $this->database->query(
            "CREATE TABLE `{$truths}` (user_id VARCHAR(191) NOT NULL PRIMARY KEY) ENGINE=InnoDB"
        );

        $this->expectException(CoreSchemaMigrationException::class);
        $this->expectExceptionMessage("unknown Personal Reading Truth state");
        (new CoreSchema1019Migration($this->database, $this->tableNames))
            ->assertPrecondition();
    }

    public function testDatabaseRejectsInvalidStateAndMissingWork(): void
    {
        $truths = $this->tableNames->personalReadingTruths();
        $this->database->suppress_errors(true);

        try {
            self::assertFalse($this->database->insert($truths, [
                "user_id" => "synthetic-user",
                "work_id" => "missing-work",
                "truth_state" => "read",
                "truth_version" => 1,
                "created_at" => "2026-09-09 10:00:00.000000",
                "updated_at" => "2026-09-09 10:00:00.000000",
            ]));
        } finally {
            $this->database->suppress_errors(false);
        }
    }
}
