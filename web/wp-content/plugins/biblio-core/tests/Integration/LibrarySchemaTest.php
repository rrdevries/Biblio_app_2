<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\LibrarySchemaMigrator;

final class LibrarySchemaTest extends PersistenceIntegrationTestCase
{
    public function testVersionedSchemaUsesRequiredMariaDbStructures(): void
    {
        $libraries = $this->tableNames->libraries();
        $memberships = $this->tableNames->memberships();

        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
        );
        self::assertSame("InnoDB", $this->tableEngine($libraries));
        self::assertSame("InnoDB", $this->tableEngine($memberships));
        self::assertSame(
            "PRIMARY KEY",
            $this->constraintType($memberships, "PRIMARY")
        );
        self::assertSame(
            "UNIQUE",
            $this->constraintType($memberships, "one_active_owner")
        );
        self::assertSame(1, $this->foreignKeyCount($memberships));
        self::assertSame(
            "STORED GENERATED",
            $this->columnExtra($memberships, "active_owner_library_id")
        );
    }

    private function tableEngine(string $tableName): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT ENGINE FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $tableName
        ));
    }

    private function constraintType(
        string $tableName,
        string $constraintName
    ): string {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $tableName,
            $constraintName
        ));
    }

    private function foreignKeyCount(string $tableName): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            DB_NAME,
            $tableName
        ));
    }

    private function columnExtra(
        string $tableName,
        string $columnName
    ): string {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT EXTRA FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s",
            DB_NAME,
            $tableName,
            $columnName
        ));
    }
}
