<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1025Migration,CoreSchemaHealthChecker,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1025ItemLocalDetailsTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        foreach (array_reverse($this->tableNames->schema1025Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->setHistoricalSchemaVersion(1024);
        (new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations()
        ))->migrate();
        parent::tearDown();
    }

    public function testMigrationIsAdditiveHealthyAndRetrySafe(): void
    {
        $table = $this->tableNames->itemLocalDetails();
        $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        $this->setHistoricalSchemaVersion(1024);
        $migration = new CoreSchema1025Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1024, $migration->sourceVersion());
        self::assertSame(1025, $migration->targetVersion());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1025)->isHealthy());
    }

    public function testUnknownPartialTableFailsClosed(): void
    {
        $table = $this->tableNames->itemLocalDetails();
        $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        $this->setHistoricalSchemaVersion(1024);
        $this->database->query(
            "CREATE TABLE `{$table}` (library_id VARCHAR(191) NOT NULL PRIMARY KEY) ENGINE=InnoDB"
        );

        $this->expectException(CoreSchemaMigrationException::class);
        $this->expectExceptionMessage("unknown Item-local details state");
        (new CoreSchema1025Migration($this->database, $this->tableNames))->assertPrecondition();
    }

    public function testDatabaseRejectsInvalidOwnershipEnumsPairsAndDates(): void
    {
        [$library, $item] = $this->seedItem("schema");
        $table = $this->tableNames->itemLocalDetails();
        self::assertSame(1, $this->database->insert($table, [
            "library_id" => $library,
            "item_id" => $item,
            "condition_code" => "goed",
            "acquisition_year" => 2020,
            "acquisition_month" => 2,
            "acquisition_day" => 29,
            "paid_amount" => "12.3400",
            "paid_currency" => "EUR",
            "signed" => 1,
            "signed_by" => "Auteur",
            "details_version" => 1,
        ]));

        $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->database->insert($table, [
                "library_id" => $library,
                "item_id" => "missing-item",
                "details_version" => 1,
            ]));
            self::assertFalse($this->database->update($table, [
                "condition_code" => "anders",
            ], ["library_id" => $library, "item_id" => $item]));
            self::assertFalse($this->database->update($table, [
                "paid_currency" => null,
            ], ["library_id" => $library, "item_id" => $item]));
            self::assertFalse($this->database->update($table, [
                "acquisition_year" => 2021,
                "acquisition_month" => 2,
                "acquisition_day" => 29,
            ], ["library_id" => $library, "item_id" => $item]));
            self::assertFalse($this->database->update($table, [
                "acquisition_year" => null,
                "acquisition_month" => 3,
                "acquisition_day" => null,
            ], ["library_id" => $library, "item_id" => $item]));
            self::assertFalse($this->database->update($table, [
                "signed" => 0,
            ], ["library_id" => $library, "item_id" => $item]));
            self::assertFalse($this->database->update($table, [
                "signed" => null,
            ], ["library_id" => $library, "item_id" => $item]));
        } finally {
            $this->database->suppress_errors(false);
        }
    }

    /** @return array{string,string} */
    private function seedItem(string $suffix): array
    {
        $library = "details-schema-library-{$suffix}";
        $work = "details-schema-work-{$suffix}";
        $edition = "details-schema-edition-{$suffix}";
        $item = "details-schema-item-{$suffix}";
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $library,
            "library_name" => "Details schema",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => $work,
            "work_title" => "Details schema",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => $edition,
            "work_id" => $work,
            "edition_title" => "Details schema",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => $item,
            "library_id" => $library,
            "edition_id" => $edition,
            "item_status" => "active",
            "item_version" => 1,
        ]);
        return [$library, $item];
    }
}
