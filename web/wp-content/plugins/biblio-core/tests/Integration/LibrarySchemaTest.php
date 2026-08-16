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
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();

        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
        );
        self::assertSame("InnoDB", $this->tableEngine($libraries));
        self::assertSame("InnoDB", $this->tableEngine($memberships));
        self::assertSame(
            "InnoDB",
            $this->tableEngine($personalLibraryDesignations)
        );
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
        self::assertSame(
            "PRIMARY KEY",
            $this->constraintType($personalLibraryDesignations, "PRIMARY")
        );
        self::assertSame(
            "UNIQUE",
            $this->constraintType(
                $personalLibraryDesignations,
                "one_personal_user_per_library"
            )
        );
        self::assertSame(
            2,
            $this->foreignKeyCount($personalLibraryDesignations)
        );
    }

    public function testVersionOneSchemaUpgradesToPersonalDesignationSchema(): void
    {
        $personalLibraryDesignations = $this->tableNames
            ->personalLibraryDesignations();
        $this->database->query(
            "DROP TABLE `{$personalLibraryDesignations}`"
        );
        update_option(LibrarySchemaMigrator::VERSION_OPTION, "1", false);

        (new LibrarySchemaMigrator(
            $this->database,
            $this->tableNames
        ))->migrate();

        self::assertSame(
            $personalLibraryDesignations,
            $this->database->get_var($this->database->prepare(
                "SHOW TABLES LIKE %s",
                $personalLibraryDesignations
            ))
        );
        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
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
