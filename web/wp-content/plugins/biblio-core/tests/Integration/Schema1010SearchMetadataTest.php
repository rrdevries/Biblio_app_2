<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1010Migration,CoreSchemaMigrationException,CoreSchemaMigrationRegistry,CoreSchemaMigrator};

final class Schema1010SearchMetadataTest extends PersistenceIntegrationTestCase
{
    public function testSchema1010IsHealthyAndHasRequiredAccessPaths(): void
    {
        $health = $this->migrator()->healthForVersion(1010);
        self::assertTrue($health->isHealthy(), $health->summary());
        self::assertSame(["isbn_10", "edition_id"], $this->indexColumns($this->tableNames->editions(), "editions_by_isbn10"));
        self::assertSame(["library_id", "inventory_number"], $this->indexColumns($this->tableNames->items(), "items_by_library_inventory_number"));
        self::assertSame(["normalized_title", "work_id"], $this->indexColumns($this->tableNames->workAlternateTitles(), "alternate_titles_by_title"));
        self::assertSame(["contained_work_id", "parent_work_id"], $this->indexColumns($this->tableNames->workContainments(), "work_containments_by_contained"));
        self::assertSame(1, $this->foreignKeyCount($this->tableNames->workAlternateTitles()));
        self::assertSame(2, $this->foreignKeyCount($this->tableNames->workContainments()));
    }

    public function testUpgradeFrom1009PreservesExistingCatalogData(): void
    {
        $this->restoreSchema1009();
        $this->database->insert($this->tableNames->works(), ["work_id" => "preserved-work", "work_title" => "Preserved"]);
        $this->database->insert($this->tableNames->editions(), ["edition_id" => "preserved-edition", "work_id" => "preserved-work"]);
        $this->database->insert($this->tableNames->libraries(), ["library_id" => "preserved-library", "library_name" => "Preserved", "library_type" => "private_library", "library_status" => "active"]);
        $this->database->insert($this->tableNames->items(), ["item_id" => "preserved-item", "library_id" => "preserved-library", "edition_id" => "preserved-edition", "item_status" => "active"]);

        $this->migrator()->migrate();

        self::assertSame(1012, $this->migrator()->installedVersion());
        self::assertSame("Preserved", $this->database->get_var("SELECT work_title FROM `{$this->tableNames->works()}` WHERE work_id='preserved-work'"));
        self::assertNull($this->database->get_var("SELECT isbn_10 FROM `{$this->tableNames->editions()}` WHERE edition_id='preserved-edition'"));
        self::assertSame("0", (string) $this->database->get_var("SELECT explicitly_no_isbn FROM `{$this->tableNames->editions()}` WHERE edition_id='preserved-edition'"));
        self::assertNull($this->database->get_var("SELECT inventory_number FROM `{$this->tableNames->items()}` WHERE item_id='preserved-item'"));
    }

    public function testKnownCompletedMigrationIsRetryable(): void
    {
        $this->restoreSchema1009();
        $migration = new CoreSchema1010Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->assertPostcondition();
        self::assertTrue($this->migrator()->healthForVersion(1010)->isHealthy());
    }

    public function testUnknownPartialStateFailsBeforeVersionBumpAndCanBeRecovered(): void
    {
        $this->restoreSchema1009();
        $this->database->query("ALTER TABLE `{$this->tableNames->editions()}` ADD isbn_10 VARCHAR(10) NULL");

        try {
            $this->migrator()->migrate();
            self::fail("Unknown partial schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString("failed before the version bump", $exception->getMessage());
            self::assertStringContainsString("unknown partial state", $exception->getMessage());
            self::assertSame(1009, $this->migrator()->installedVersion());
        }

        $this->database->query("ALTER TABLE `{$this->tableNames->editions()}` DROP COLUMN isbn_10");
        $this->migrator()->migrate();
        self::assertSame(1012, $this->migrator()->installedVersion());
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production($this->database, $this->tableNames)->migrations()
        );
    }

    private function restoreSchema1009(): void
    {
        foreach (array_reverse($this->tableNames->schema1010Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        if (in_array("inventory_number", $this->columns($this->tableNames->items()), true)) {
            $items = $this->tableNames->items();
            $this->database->query("ALTER TABLE `{$items}` DROP INDEX items_by_library_inventory_number");
            $this->database->query("ALTER TABLE `{$items}` DROP CONSTRAINT items_inventory_number_non_empty");
            $this->database->query("ALTER TABLE `{$items}` DROP COLUMN inventory_number");
        }
        if (in_array("isbn_10", $this->columns($this->tableNames->editions()), true)) {
            $editions = $this->tableNames->editions();
            $this->database->query("ALTER TABLE `{$editions}` DROP INDEX editions_by_isbn10");
            $this->database->query("ALTER TABLE `{$editions}` DROP INDEX editions_by_isbn13");
            foreach (["edition_isbn_flag_valid", "edition_isbn_state_valid", "edition_isbn10_shape_valid", "edition_isbn13_shape_valid"] as $constraint) {
                $this->database->query("ALTER TABLE `{$editions}` DROP CONSTRAINT `{$constraint}`");
            }
            $this->database->query("ALTER TABLE `{$editions}` DROP COLUMN isbn_10, DROP COLUMN isbn_13, DROP COLUMN explicitly_no_isbn");
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1009", false);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map("strval", $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )));
    }

    /** @return list<string> */
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
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_TYPE='FOREIGN KEY'",
            DB_NAME,
            $table
        ));
    }
}
