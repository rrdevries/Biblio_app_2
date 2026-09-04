<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1011Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1011LocationTest extends PersistenceIntegrationTestCase
{
    public function testSchema1011IsHealthyAndIndexed(): void
    {
        $health = $this->migrator()->healthForVersion(1011);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(
            ["library_id", "display_name", "location_id"],
            $this->indexColumns($this->tableNames->locations(), "locations_by_library_name")
        );
        self::assertSame(
            ["library_id", "location_id", "item_id"],
            $this->indexColumns($this->tableNames->items(), "items_by_library_location")
        );
        self::assertSame(1, $this->foreignKeyCount($this->tableNames->locations()));
        self::assertSame(3, $this->foreignKeyCount($this->tableNames->items()));
    }

    public function testUpgradeFrom1010PreservesItemsAndAddsNullLocation(): void
    {
        $this->restoreSchema1010();
        $this->database->insert($this->tableNames->works(), ["work_id" => "preserved-work", "work_title" => "Preserved"]);
        $this->database->insert($this->tableNames->editions(), ["edition_id" => "preserved-edition", "work_id" => "preserved-work", "explicitly_no_isbn" => 0]);
        $this->database->insert($this->tableNames->libraries(), ["library_id" => "preserved-library", "library_name" => "Preserved", "library_type" => "private_library", "library_status" => "active"]);
        $this->database->insert($this->tableNames->items(), ["item_id" => "preserved-item", "library_id" => "preserved-library", "edition_id" => "preserved-edition", "item_status" => "active"]);

        $this->migrator()->migrate();

        self::assertSame(1012, $this->migrator()->installedVersion());
        self::assertNull($this->database->get_var(
            "SELECT location_id FROM `{$this->tableNames->items()}` WHERE item_id='preserved-item'"
        ));
    }

    public function testCompletedMigrationIsRetryable(): void
    {
        $this->restoreSchema1010();
        $migration = new CoreSchema1011Migration($this->database, $this->tableNames);
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->assertPostcondition();
        self::assertTrue($this->migrator()->healthForVersion(1011)->isHealthy());
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1011", false);
        $this->migrator()->migrate();
    }

    public function testUnknownPartialItemStateFailsBeforeVersionBump(): void
    {
        $this->restoreSchema1010();
        $this->database->query(
            "ALTER TABLE `{$this->tableNames->items()}` ADD location_id VARCHAR(191) NULL"
        );
        try {
            $this->migrator()->migrate();
            self::fail("Unknown partial schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("failed before the version bump", $exception->getMessage());
            self::assertSame(1010, $this->migrator()->installedVersion());
        } finally {
            $this->database->query(
                "ALTER TABLE `{$this->tableNames->items()}` DROP COLUMN location_id"
            );
            $this->migrator()->migrate();
        }
    }

    private function restoreSchema1010(): void
    {
        $items = $this->tableNames->items();
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->itemArchivePeriods()}`");
        if (in_array("item_version", $this->columns($items), true)) {
            $this->database->query("ALTER TABLE `{$items}` DROP INDEX items_by_library_status_location");
            $this->database->query("ALTER TABLE `{$items}` DROP INDEX items_by_library_identity");
            $this->database->query("ALTER TABLE `{$items}` DROP CONSTRAINT items_status_supported");
            $this->database->query("ALTER TABLE `{$items}` DROP CONSTRAINT items_version_positive");
            $this->database->query("ALTER TABLE `{$items}` DROP COLUMN item_version");
            $this->database->query("ALTER TABLE `{$items}` ADD CONSTRAINT items_status_active CHECK (item_status IN ('active'))");
        }
        if (in_array("location_id", $this->columns($items), true)) {
            $this->database->query("ALTER TABLE `{$items}` DROP FOREIGN KEY items_location_fk");
            $this->database->query("ALTER TABLE `{$items}` DROP INDEX items_by_library_location");
            $this->database->query("ALTER TABLE `{$items}` DROP COLUMN location_id");
        }
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->locations()}`");
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1010", false);
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations()
        );
    }

    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )));
    }

    private function indexColumns(string $table, string $index): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX",
            DB_NAME,
            $table,
            $index
        )));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND REFERENCED_TABLE_NAME IS NOT NULL",
            DB_NAME,
            $table
        ));
    }
}
