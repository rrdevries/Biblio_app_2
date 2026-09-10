<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1016Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1016CatalogTitleSeparationTest extends PersistenceIntegrationTestCase
{
    public function testMigrationPreservesVisibleTitlesAndMarksWorksProvisional(): void
    {
        $this->restoreSchema1015();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-a",
            "work_title" => "Bestaande titel",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-a",
            "work_id" => "work-a",
        ]);

        try {
            $this->migrator()->migrate();

            self::assertSame(1020, $this->migrator()->installedVersion());
            self::assertTrue($this->migrator()->health()->isHealthy());
            self::assertSame("Bestaande titel", $this->database->get_var(
                "SELECT edition_title FROM `{$editions}` WHERE edition_id='edition-a'"
            ));
            self::assertSame("Bestaande titel", $this->database->get_var(
                "SELECT work_title FROM `{$works}` WHERE work_id='work-a'"
            ));
            self::assertSame("provisional", $this->database->get_var(
                "SELECT work_title_status FROM `{$works}` WHERE work_id='work-a'"
            ));
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testKnownPartialAndCompletedMigrationAreRetrySafe(): void
    {
        $this->restoreSchema1015();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-retry",
            "work_title" => "Retry title",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-retry",
            "work_id" => "work-retry",
        ]);
        $this->database->query(
            "ALTER TABLE `{$works}` ADD work_title_status "
                . "VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $this->database->query(
            "ALTER TABLE `{$editions}` ADD edition_title "
                . "VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $migration = new CoreSchema1016Migration($this->database, $this->tableNames);

        try {
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPostcondition();
            $this->migrator()->migrate();

            self::assertSame(1020, $this->migrator()->installedVersion());
            self::assertTrue($this->migrator()->health()->isHealthy());
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testDivergentWorkStatusFailsClosedBeforeVersionBump(): void
    {
        $this->restoreSchema1015();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-divergent",
            "work_title" => "Original",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-divergent",
            "work_id" => "work-divergent",
        ]);
        $this->database->query(
            "ALTER TABLE `{$works}` ADD work_title_status "
                . "VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $this->database->query(
            "ALTER TABLE `{$editions}` ADD edition_title "
                . "VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $this->database->update(
            $works,
            ["work_title_status" => "librarian_confirmed"],
            ["work_id" => "work-divergent"]
        );
        try {
            (new CoreSchema1016Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Divergent Work title status was adopted.");
        } catch (CoreSchemaMigrationException) {
            self::assertSame(1015, $this->migrator()->installedVersion());
        } finally {
            $this->restoreSchema1015();
            $this->restoreCurrentSchema();
        }
    }

    public function testDivergentEditionTitleFailsClosedBeforeVersionBump(): void
    {
        $this->restoreSchema1015();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-divergent-edition",
            "work_title" => "Original",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-divergent-title",
            "work_id" => "work-divergent-edition",
        ]);
        $this->database->query(
            "ALTER TABLE `{$works}` ADD work_title_status "
                . "VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $this->database->query(
            "ALTER TABLE `{$editions}` ADD edition_title "
                . "VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL"
        );
        $this->database->update(
            $works,
            ["work_title_status" => "provisional"],
            ["work_id" => "work-divergent-edition"]
        );
        $this->database->update(
            $editions,
            ["edition_title" => "Other"],
            ["edition_id" => "edition-divergent-title"]
        );

        try {
            (new CoreSchema1016Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Divergent Edition title was adopted.");
        } catch (CoreSchemaMigrationException) {
            self::assertSame(1015, $this->migrator()->installedVersion());
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testUnknownEditionTitleDefaultFailsClosedBeforeVersionBump(): void
    {
        $this->restoreSchema1015();
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $this->database->insert($works, [
            "work_id" => "work-default-drift",
            "work_title" => "Original",
        ]);
        $this->database->insert($editions, [
            "edition_id" => "edition-default-drift",
            "work_id" => "work-default-drift",
        ]);
        $this->database->query(
            "ALTER TABLE `{$editions}` ADD edition_title "
                . "VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin "
                . "NOT NULL DEFAULT 'Unknown'"
        );

        try {
            (new CoreSchema1016Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Unknown Edition title default was adopted.");
        } catch (CoreSchemaMigrationException) {
            self::assertSame(1015, $this->migrator()->installedVersion());
        } finally {
            $this->restoreSchema1015();
            $this->restoreCurrentSchema();
        }
    }

    public function testCurrentHealthRejectsEditionTitleDefaultDrift(): void
    {
        $editions = $this->tableNames->editions();
        $this->database->query(
            "ALTER TABLE `{$editions}` MODIFY edition_title "
                . "VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin "
                . "NOT NULL DEFAULT 'Unknown'"
        );

        try {
            $health = $this->migrator()->health();

            self::assertFalse($health->isHealthy());
            self::assertStringContainsString(
                "edition_title expected default no default; found Unknown",
                $health->summary()
            );
        } finally {
            $this->restoreSchema1015();
            $this->restoreCurrentSchema();
        }

        self::assertTrue($this->migrator()->health()->isHealthy());
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        );
    }

    private function restoreSchema1015(): void
    {
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        if ($this->hasConstraint($works, "work_title_status_supported")) {
            $this->database->query(
                "ALTER TABLE `{$works}` DROP CONSTRAINT work_title_status_supported"
            );
        }
        if ($this->hasColumn($works, "work_title_status")) {
            $this->database->query(
                "ALTER TABLE `{$works}` DROP COLUMN work_title_status"
            );
        }
        if ($this->hasConstraint($editions, "edition_title_non_empty")) {
            $this->database->query(
                "ALTER TABLE `{$editions}` DROP CONSTRAINT edition_title_non_empty"
            );
        }
        if ($this->hasColumn($editions, "edition_title")) {
            $this->database->query(
                "ALTER TABLE `{$editions}` DROP COLUMN edition_title"
            );
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1015", false);
    }

    private function restoreCurrentSchema(): void
    {
        if ($this->hasColumn($this->tableNames->works(), "work_title_status")) {
            $this->database->query(
                "UPDATE `{$this->tableNames->works()}` "
                    . "SET work_title_status='provisional'"
            );
        }
        if ($this->hasColumn($this->tableNames->editions(), "edition_title")) {
            $this->database->query(
                "UPDATE `{$this->tableNames->editions()}` e "
                    . "INNER JOIN `{$this->tableNames->works()}` w "
                    . "ON w.work_id=e.work_id SET e.edition_title=w.work_title"
            );
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1015", false);
        $this->migrator()->migrate();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
                . "WHERE CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s "
                . "AND CONSTRAINT_NAME=%s",
            DB_NAME,
            $table,
            $constraint
        )) === 1;
    }
}
