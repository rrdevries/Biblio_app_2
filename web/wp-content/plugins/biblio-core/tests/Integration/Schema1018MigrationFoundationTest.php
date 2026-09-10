<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1018Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1018MigrationFoundationTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        foreach (array_reverse($this->tableNames->schema1018Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        $this->setHistoricalSchemaVersion(1017);
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

    public function testMigrationCreatesHealthyAdditiveFoundationAndIsRetrySafe(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        foreach (array_reverse($this->tableNames->schema1018Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        $this->setHistoricalSchemaVersion(1017);
        $migration = new CoreSchema1018Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1017, $migration->sourceVersion());
        self::assertSame(1018, $migration->targetVersion());
        self::assertCount(6, $this->tableNames->schema1018Additions());
    }

    public function testUnknownPartialFoundationFailsClosed(): void
    {
        foreach (array_reverse($this->tableNames->schema1019Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        foreach (array_reverse($this->tableNames->schema1018Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS " . $table);
        }
        $this->setHistoricalSchemaVersion(1017);
        $runs = $this->tableNames->migrationRuns();
        $this->database->query(
            "CREATE TABLE " . $runs . " (run_id VARCHAR(191) NOT NULL PRIMARY KEY) ENGINE=InnoDB"
        );

        $this->expectException(CoreSchemaMigrationException::class);
        $this->expectExceptionMessage("unknown migration foundation state");
        (new CoreSchema1018Migration($this->database, $this->tableNames))
            ->assertPrecondition();
    }
}
