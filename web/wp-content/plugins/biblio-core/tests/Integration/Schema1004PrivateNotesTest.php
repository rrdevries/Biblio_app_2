<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1004Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1004PrivateNotesTest extends PersistenceIntegrationTestCase
{
    public function testSchema1004HasExactColumnsIndexesChecksAndForeignKeys(): void
    {
        $table = $this->tableNames->privateNotes();
        $columns = $this->database->get_col($this->database->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION",
            DB_NAME,
            $table
        ));

        self::assertSame([
            'private_note_id', 'user_id', 'work_id', 'reading_round_id',
            'note_content', 'created_at', 'updated_at', 'note_version',
        ], $columns);
        self::assertSame(6, $this->indexCount($table));
        self::assertSame(2, $this->foreignKeyCount($table));
        self::assertSame('SET NULL', $this->deleteRule($table, 'reading_round_id'));
        self::assertSame('RESTRICT', $this->deleteRule($table, 'work_id'));
        self::assertGreaterThanOrEqual(2, $this->checkCount($table));
        self::assertSame(0, (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_TYPE = 'FULLTEXT'",
            DB_NAME,
            $table
        )));
        self::assertTrue($this->migrator()->healthForVersion(1004)->isHealthy());
    }

    public function testUpgradeFrom1003AndCreateTableBeforeVersionBumpRetryConverge(): void
    {
        $notes = $this->tableNames->privateNotes();
        $works = $this->tableNames->works();
        $this->database->insert($works, [
            'work_id' => 'pre-1004-work',
            'work_title' => 'Blijft behouden',
        ]);
        $this->database->query("DROP TABLE `{$notes}`");
        update_option(CoreSchemaMigrator::VERSION_OPTION, '1003', false);
        $migration = new CoreSchema1004Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        self::assertSame('1003', (string) get_option(
            CoreSchemaMigrator::VERSION_OPTION
        ));
        $this->migrator()->migrate();

        self::assertSame(1010, $this->migrator()->installedVersion());
        self::assertSame('Blijft behouden', $this->database->get_var(
            $this->database->prepare(
                "SELECT work_title FROM `{$works}` WHERE work_id = %s",
                'pre-1004-work'
            )
        ));
        self::assertTrue($this->migrator()->health()->isHealthy());
    }

    public function testUnknownPartialPrivateNoteTableFailsClosedAtVersion1003(): void
    {
        $notes = $this->tableNames->privateNotes();
        $this->database->query("DROP TABLE `{$notes}`");
        $this->database->query(
            "CREATE TABLE `{$notes}` (private_note_id VARCHAR(191) NOT NULL PRIMARY KEY) "
            . "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, '1003', false);

        try {
            $this->migrator()->migrate();
            self::fail('Unknown partial schema 1004 table was accepted.');
        } catch (CoreSchemaMigrationException $failure) {
            self::assertStringContainsString(
                'unknown Private Note table state',
                $failure->getMessage()
            );
            self::assertSame('1003', (string) get_option(
                CoreSchemaMigrator::VERSION_OPTION
            ));
        } finally {
            $this->database->query("DROP TABLE `{$notes}`");
            update_option(CoreSchemaMigrator::VERSION_OPTION, '1003', false);
            $this->migrator()->migrate();
        }
    }

    public function testPrivateNoteIndexDriftIsDetectedWithoutRepair(): void
    {
        $table = $this->tableNames->privateNotes();
        $index = 'private_notes_by_user_updated';
        $this->database->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");

        try {
            $health = $this->migrator()->health();
            self::assertFalse($health->isHealthy());
            self::assertStringContainsString(
                "missing required index {$index}",
                $health->summary()
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$table}` ADD INDEX `{$index}` "
                . "(user_id, updated_at, private_note_id)"
            );
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

    private function indexCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
    }

    private function foreignKeyCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            DB_NAME,
            $table
        ));
    }

    private function checkCount(string $table): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_TYPE = 'CHECK'",
            DB_NAME,
            $table
        ));
    }

    private function deleteRule(string $table, string $column): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k "
            . "INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
            . "ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA "
            . "AND r.TABLE_NAME = k.TABLE_NAME "
            . "AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME "
            . "WHERE k.CONSTRAINT_SCHEMA = %s AND k.TABLE_NAME = %s "
            . "AND k.COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        ));
    }
}
