<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1007Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1007LibraryIdentityTest extends PersistenceIntegrationTestCase
{
    public function testReal1006UpgradeBackfillsNamesAndIsIdempotent(): void
    {
        $libraries = $this->tableNames->libraries();
        $this->database->query(
            "ALTER TABLE `{$libraries}` DROP CONSTRAINT `libraries_name_non_empty`"
        );
        $this->database->query(
            "ALTER TABLE `{$libraries}` DROP COLUMN library_name"
        );
        $memberships = $this->tableNames->memberships();
        $this->database->query(
            "ALTER TABLE `{$memberships}` DROP INDEX `memberships_by_user`"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1006", false);
        $this->database->insert($libraries, [
            "library_id" => "legacy-personal",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "legacy-personal",
            "user_id" => "legacy-user",
            "membership_status" => "active",
            "management_role" => "owner",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]);
        $this->database->insert(
            $this->tableNames->personalLibraryDesignations(),
            ["user_id" => "legacy-user", "library_id" => "legacy-personal"]
        );

        try {
            $this->migrator()->migrate();

            self::assertSame(1012, $this->migrator()->installedVersion());
            self::assertSame(
                "Mijn Bibliotheek",
                $this->database->get_var(
                    "SELECT library_name FROM `{$libraries}` "
                    . "WHERE library_id = 'legacy-personal'"
                )
            );
            self::assertSame("NO", $this->columnNullable($libraries, "library_name"));
            self::assertSame(
                ["user_id", "library_id"],
                $this->indexColumns(
                    $memberships,
                    "memberships_by_user"
                )
            );
            self::assertTrue($this->migrator()->health()->isHealthy());

            $migration = new CoreSchema1007Migration(
                $this->database,
                $this->tableNames
            );
            $before = $this->database->get_row(
                "SELECT * FROM `{$libraries}` WHERE library_id = 'legacy-personal'",
                ARRAY_A
            );
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPostcondition();
            self::assertSame($before, $this->database->get_row(
                "SELECT * FROM `{$libraries}` WHERE library_id = 'legacy-personal'",
                ARRAY_A
            ));
        } finally {
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1006", false);
            $this->migrator()->migrate();
        }
    }

    public function testSchemaRejectsMissingAndEmptyLibraryNames(): void
    {
        $libraries = $this->tableNames->libraries();
        $previous = $this->database->suppress_errors(true);

        try {
            self::assertFalse($this->database->insert($libraries, [
                "library_id" => "missing-name",
                "library_type" => "private_library",
                "library_status" => "active",
            ]));
            self::assertFalse($this->database->insert($libraries, [
                "library_id" => "empty-name",
                "library_name" => "  ",
                "library_type" => "private_library",
                "library_status" => "active",
            ]));
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    public function testUnknownActorIndexPartialFailsClosed(): void
    {
        $libraries = $this->tableNames->libraries();
        $memberships = $this->tableNames->memberships();
        $this->database->query(
            "ALTER TABLE `{$libraries}` DROP CONSTRAINT `libraries_name_non_empty`"
        );
        $this->database->query(
            "ALTER TABLE `{$libraries}` DROP COLUMN library_name"
        );
        $this->database->query(
            "ALTER TABLE `{$memberships}` DROP INDEX `memberships_by_user`"
        );
        $this->database->query(
            "ALTER TABLE `{$memberships}` ADD INDEX `memberships_by_user` "
            . "(library_id, user_id)"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1006", false);

        try {
            $migration = new CoreSchema1007Migration(
                $this->database,
                $this->tableNames
            );

            $migration->assertPrecondition();
            self::fail("An unknown actor-membership index must fail closed.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown actor-membership index",
                $exception->getMessage()
            );
            self::assertSame(1006, $this->migrator()->installedVersion());
        } finally {
            $this->database->query(
                "ALTER TABLE `{$memberships}` DROP INDEX `memberships_by_user`"
            );
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1006", false);
            $this->migrator()->migrate();
        }
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

    private function columnNullable(string $table, string $column): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        ));
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        return array_map("strval", $this->database->get_col(
            $this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
                . "AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX",
                DB_NAME,
                $table,
                $index
            )
        ));
    }
}
