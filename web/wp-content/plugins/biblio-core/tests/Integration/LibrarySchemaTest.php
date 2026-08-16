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
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $items = $this->tableNames->items();
        $externalLoans = $this->tableNames->externalLoans();
        $readingRounds = $this->tableNames->readingRounds();

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
        self::assertSame("InnoDB", $this->tableEngine($works));
        self::assertSame("InnoDB", $this->tableEngine($editions));
        self::assertSame("InnoDB", $this->tableEngine($items));
        self::assertSame("InnoDB", $this->tableEngine($externalLoans));
        self::assertSame("InnoDB", $this->tableEngine($readingRounds));
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
        self::assertSame(1, $this->foreignKeyCount($editions));
        self::assertSame(2, $this->foreignKeyCount($items));
        self::assertFalse($this->columnExists($works, "library_id"));
        self::assertFalse($this->columnExists($editions, "library_id"));
        self::assertTrue($this->columnExists($items, "library_id"));
        self::assertSame(1, $this->foreignKeyCount($externalLoans));
        self::assertTrue($this->columnExists($externalLoans, "user_id"));
        self::assertFalse($this->columnExists($externalLoans, "library_id"));
        self::assertSame(
            "NO",
            $this->columnNullability($externalLoans, "borrowed_at")
        );
        self::assertSame(
            "YES",
            $this->columnNullability($externalLoans, "due_at")
        );
        self::assertTrue($this->indexExists(
            $externalLoans,
            "external_loans_by_user"
        ));
        self::assertSame(3, $this->foreignKeyCount($readingRounds));
        self::assertFalse($this->columnExists($readingRounds, "library_id"));
        self::assertSame(
            "YES",
            $this->columnNullability($readingRounds, "item_id")
        );
        self::assertSame(
            "YES",
            $this->columnNullability($readingRounds, "external_loan_id")
        );
        self::assertSame(
            "STORED GENERATED",
            $this->columnExtra($readingRounds, "active_item_user_id")
        );
        self::assertSame(
            "STORED GENERATED",
            $this->columnExtra(
                $readingRounds,
                "active_external_loan_user_id"
            )
        );
        self::assertSame(
            "UNIQUE",
            $this->constraintType(
                $readingRounds,
                "one_active_item_round_per_user"
            )
        );
        self::assertSame(
            "UNIQUE",
            $this->constraintType(
                $readingRounds,
                "one_active_external_round_per_user"
            )
        );
    }

    public function testVersionFourSchemaUpgradesToReadingRoundSchema(): void
    {
        $readingRounds = $this->tableNames->readingRounds();
        $this->database->query("DROP TABLE `{$readingRounds}`");
        update_option(LibrarySchemaMigrator::VERSION_OPTION, "4", false);

        (new LibrarySchemaMigrator(
            $this->database,
            $this->tableNames
        ))->migrate();

        self::assertSame(
            $readingRounds,
            $this->existingTable($readingRounds)
        );
        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
        );
    }

    public function testVersionThreeSchemaUpgradesToExternalLoanSchema(): void
    {
        $readingRounds = $this->tableNames->readingRounds();
        $externalLoans = $this->tableNames->externalLoans();
        $this->database->query("DROP TABLE `{$readingRounds}`");
        $this->database->query("DROP TABLE `{$externalLoans}`");
        update_option(LibrarySchemaMigrator::VERSION_OPTION, "3", false);

        (new LibrarySchemaMigrator(
            $this->database,
            $this->tableNames
        ))->migrate();

        self::assertSame(
            $externalLoans,
            $this->existingTable($externalLoans)
        );
        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
        );
    }

    public function testVersionTwoSchemaUpgradesToCatalogSchema(): void
    {
        $readingRounds = $this->tableNames->readingRounds();
        $externalLoans = $this->tableNames->externalLoans();
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $this->database->query("DROP TABLE `{$readingRounds}`");
        $this->database->query("DROP TABLE `{$externalLoans}`");
        $this->database->query("DROP TABLE `{$items}`");
        $this->database->query("DROP TABLE `{$editions}`");
        $this->database->query("DROP TABLE `{$works}`");
        update_option(LibrarySchemaMigrator::VERSION_OPTION, "2", false);

        (new LibrarySchemaMigrator(
            $this->database,
            $this->tableNames
        ))->migrate();

        self::assertSame($works, $this->existingTable($works));
        self::assertSame($editions, $this->existingTable($editions));
        self::assertSame($items, $this->existingTable($items));
        self::assertSame(
            (string) LibrarySchemaMigrator::CURRENT_VERSION,
            get_option(LibrarySchemaMigrator::VERSION_OPTION)
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

    private function columnExists(
        string $tableName,
        string $columnName
    ): bool {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s",
            DB_NAME,
            $tableName,
            $columnName
        )) === 1;
    }

    private function existingTable(string $tableName): ?string
    {
        return $this->database->get_var($this->database->prepare(
            "SHOW TABLES LIKE %s",
            $tableName
        ));
    }

    private function columnNullability(
        string $tableName,
        string $columnName
    ): string {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s",
            DB_NAME,
            $tableName,
            $columnName
        ));
    }

    private function indexExists(
        string $tableName,
        string $indexName
    ): bool {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND INDEX_NAME = %s",
            DB_NAME,
            $tableName,
            $indexName
        )) > 0;
    }
}
