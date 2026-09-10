<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1020Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1020HistoricalArchiveReasonTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        if (!$this->hasColumn("archive_reason_kind")) {
            $migration = new CoreSchema1020Migration(
                $this->database,
                $this->tableNames
            );
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPostcondition();
        }

        parent::tearDown();
    }

    public function testUpgradePreservesNativeReasonsAndIsRetrySafe(): void
    {
        $this->restoreSchema1019();
        $this->seedArchivedItem();
        $table = $this->tableNames->itemArchivePeriods();
        self::assertSame(1, $this->database->insert($table, [
            "library_id" => "library-archive-1020",
            "item_id" => "item-archive-1020",
            "archive_version" => 2,
            "archive_reason" => "sold",
            "archived_at" => "2026-09-10 08:00:00.123456",
        ]), $this->database->last_error);

        $migration = new CoreSchema1020Migration(
            $this->database,
            $this->tableNames
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        $row = $this->database->get_row(
            "SELECT archive_reason_kind,archive_reason,"
                . "preserved_reason_text,preserved_reason_value "
                . "FROM `{$table}` WHERE item_id='item-archive-1020'"
        );
        self::assertSame("native", $row->archive_reason_kind);
        self::assertSame("sold", $row->archive_reason);
        self::assertNull($row->preserved_reason_text);
        self::assertNull($row->preserved_reason_value);
        self::assertSame(1019, $migration->sourceVersion());
        self::assertSame(1020, $migration->targetVersion());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1020)->isHealthy());
    }

    public function testUnknownPartialStateFailsClosed(): void
    {
        $this->restoreSchema1019();
        $table = $this->tableNames->itemArchivePeriods();
        $this->database->query(
            "ALTER TABLE `{$table}` ADD archive_reason_kind "
                . "VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin "
                . "NOT NULL DEFAULT 'native' AFTER archive_version"
        );

        try {
            (new CoreSchema1020Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Unknown partial archive reason state was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown Item archive reason state",
                $exception->getMessage()
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` DROP COLUMN archive_reason_kind"
            );
            (new CoreSchema1020Migration($this->database, $this->tableNames))
                ->migrate();
        }
    }

    public function testDatabaseRejectsMixedOrEmptyReasonShapes(): void
    {
        $this->seedArchivedItem();
        $table = $this->tableNames->itemArchivePeriods();
        $this->database->suppress_errors(true);

        try {
            self::assertFalse($this->database->insert($table, [
                "library_id" => "library-archive-1020",
                "item_id" => "item-archive-1020",
                "archive_version" => 2,
                "archive_reason_kind" => "preserved_historical",
                "archive_reason" => "sold",
                "preserved_reason_text" => "historical reason A",
                "archived_at" => "2026-09-10 08:00:00.123456",
            ]));
            self::assertFalse($this->database->insert($table, [
                "library_id" => "library-archive-1020",
                "item_id" => "item-archive-1020",
                "archive_version" => 3,
                "archive_reason_kind" => "preserved_historical",
                "archive_reason" => null,
                "preserved_reason_text" => "   ",
                "archived_at" => "2026-09-10 09:00:00.123456",
            ]));
        } finally {
            $this->database->suppress_errors(false);
        }
    }

    private function restoreSchema1019(): void
    {
        if (!$this->hasColumn("archive_reason_kind")) {
            $this->setHistoricalSchemaVersion(1019);
            return;
        }

        $table = $this->tableNames->itemArchivePeriods();
        $this->database->query(
            "ALTER TABLE `{$table}` "
                . "DROP CONSTRAINT item_archive_reason_kind_supported,"
                . "DROP CONSTRAINT item_archive_reason_shape,"
                . "DROP CONSTRAINT item_archive_preserved_text_valid,"
                . "DROP CONSTRAINT item_archive_preserved_value_valid,"
                . "MODIFY archive_reason VARCHAR(32) NOT NULL,"
                . "DROP COLUMN preserved_reason_value,"
                . "DROP COLUMN preserved_reason_text,"
                . "DROP COLUMN archive_reason_kind,"
                . "ADD CONSTRAINT item_archive_reason_supported CHECK (archive_reason IN ('sold','given_away','donated','lost','damaged_discarded','not_returned'))"
        );
        $this->setHistoricalSchemaVersion(1019);
    }

    private function seedArchivedItem(): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => "work-archive-1020", "work_title" => "Synthetic Work"]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->editions(),
            [
                "edition_id" => "edition-archive-1020",
                "work_id" => "work-archive-1020",
                "edition_title" => "Synthetic Edition",
                "explicitly_no_isbn" => 0,
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraries(),
            [
                "library_id" => "library-archive-1020",
                "library_name" => "Synthetic Library",
                "library_type" => "private_library",
                "library_status" => "active",
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->items(),
            [
                "item_id" => "item-archive-1020",
                "library_id" => "library-archive-1020",
                "edition_id" => "edition-archive-1020",
                "item_status" => "archived",
                "item_version" => 2,
            ]
        ), $this->database->last_error);
    }

    private function hasColumn(string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $this->tableNames->itemArchivePeriods(),
            $column
        )) === 1;
    }
}
