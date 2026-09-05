<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1012Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1012ItemArchiveTest extends PersistenceIntegrationTestCase
{
    public function testSchema1012IsHealthyConstrainedAndIndexed(): void
    {
        $health = $this->migrator()->healthForVersion(1012);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(["library_id", "item_status", "location_id", "item_id"], $this->indexColumns($this->tableNames->items(), "items_by_library_status_location"));
        self::assertSame(["library_id", "item_id"], $this->indexColumns($this->tableNames->items(), "items_by_library_identity"));
        self::assertSame(["library_id", "open_item_id"], $this->indexColumns($this->tableNames->itemArchivePeriods(), "one_open_item_archive_period"));
        self::assertSame(1, $this->foreignKeyCount($this->tableNames->itemArchivePeriods()));
    }

    public function testUpgradePreservesExistingItemAsActiveWithLocationAndInitialVersion(): void
    {
        $this->restoreSchema1011();
        $this->database->insert($this->tableNames->works(), ["work_id" => "work-a", "work_title" => "Work"]);
        $this->database->insert($this->tableNames->editions(), ["edition_id" => "edition-a", "work_id" => "work-a", "explicitly_no_isbn" => 0]);
        $this->database->insert($this->tableNames->libraries(), ["library_id" => "library-a", "library_name" => "Library", "library_type" => "private_library", "library_status" => "active"]);
        $this->database->insert($this->tableNames->locations(), ["library_id" => "library-a", "location_id" => "location-a", "display_name" => "Kast"]);
        $this->database->insert($this->tableNames->items(), ["item_id" => "item-a", "library_id" => "library-a", "edition_id" => "edition-a", "item_status" => "active", "location_id" => "location-a"]);

        $this->migrator()->migrate();
        $row = $this->database->get_row("SELECT item_id,item_status,item_version,location_id FROM `{$this->tableNames->items()}` WHERE item_id='item-a'");
        self::assertSame("item-a", $row->item_id);
        self::assertSame("active", $row->item_status);
        self::assertSame("1", (string) $row->item_version);
        self::assertSame("location-a", $row->location_id);
        self::assertSame(0, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->itemArchivePeriods()}`"));
    }

    public function testCompletedAndKnownPartialMigrationAreRetryable(): void
    {
        $this->restoreSchema1011();
        $this->applyItemLifecycleAlter();
        $migration = new CoreSchema1012Migration($this->database, $this->tableNames);
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->assertPostcondition();
        $this->migrator()->migrate();
        self::assertSame(1014, $this->migrator()->installedVersion());
    }

    public function testUnknownPartialStateFailsBeforeVersionBump(): void
    {
        $this->restoreSchema1011();
        $items = $this->tableNames->items();
        $this->database->query("ALTER TABLE `{$items}` ADD item_version BIGINT UNSIGNED NOT NULL DEFAULT 1");
        try {
            $this->migrator()->migrate();
            self::fail("Unknown partial schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("failed before the version bump", $exception->getMessage());
            self::assertSame(1011, $this->migrator()->installedVersion());
        } finally {
            $this->database->query("ALTER TABLE `{$items}` DROP COLUMN item_version");
            $this->migrator()->migrate();
        }
    }

    private function restoreSchema1011(): void
    {
        $items = $this->tableNames->items();
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->collectionMemberships()}`");
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->collections()}`");
        $this->database->query("DROP TABLE IF EXISTS `{$this->tableNames->itemArchivePeriods()}`");
        if (in_array("item_version", $this->columns($items), true)) {
            foreach (["items_by_library_status_location", "items_by_library_identity"] as $index) {
                $this->database->query("ALTER TABLE `{$items}` DROP INDEX `{$index}`");
            }
            foreach (["items_status_supported", "items_version_positive"] as $constraint) {
                $this->database->query("ALTER TABLE `{$items}` DROP CONSTRAINT `{$constraint}`");
            }
            $this->database->query("ALTER TABLE `{$items}` DROP COLUMN item_version");
            $this->database->query("ALTER TABLE `{$items}` ADD CONSTRAINT items_status_active CHECK (item_status IN ('active'))");
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1011", false);
    }

    private function applyItemLifecycleAlter(): void
    {
        $items = $this->tableNames->items();
        $this->database->query("ALTER TABLE `{$items}` DROP CONSTRAINT items_status_active,ADD item_version BIGINT UNSIGNED NOT NULL DEFAULT 1,ADD UNIQUE KEY items_by_library_identity (library_id,item_id),ADD KEY items_by_library_status_location (library_id,item_status,location_id,item_id),ADD CONSTRAINT items_status_supported CHECK (item_status IN ('active','archived')),ADD CONSTRAINT items_version_positive CHECK (item_version >= 1)");
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator($this->database, $this->tableNames, CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations());
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s", DB_NAME, $table)));
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX", DB_NAME, $table, $index)));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare("SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND REFERENCED_TABLE_NAME IS NOT NULL", DB_NAME, $table));
    }
}
