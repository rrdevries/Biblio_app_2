<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Closure;

final class Schema1001IntegrityTest extends PersistenceIntegrationTestCase
{
    public function testTermUniquenessIncludesInactiveAndAllowsNullSeedKeys(): void
    {
        $this->insertLibrary("library-a");
        $this->insertTerm(
            $this->tableNames->libraryGenres(),
            "genre_id",
            "library-a",
            "genre-a",
            "Sci-Fi",
            "sci fi",
            "active"
        );

        $this->assertDatabaseRejects(fn (): int|false => $this->insertTerm(
            $this->tableNames->libraryGenres(),
            "genre_id",
            "library-a",
            "genre-b",
            "Sci Fi",
            "sci fi",
            "inactive"
        ));

        $this->insertTerm(
            $this->tableNames->libraryGenres(),
            "genre_id",
            "library-a",
            "genre-c",
            "Fantasy",
            "fantasy",
            "active"
        );

        self::assertSame(2, $this->tableCount(
            $this->tableNames->libraryGenres()
        ));
    }

    public function testContextIdentityAndCompositeForeignKeysEnforceLibraryScope(): void
    {
        $this->insertLibrary("library-a");
        $this->insertLibrary("library-b");
        $this->insertWork("shared-work");
        $this->insertWork("other-work");
        $this->insertTerm(
            $this->tableNames->libraryBookTypes(),
            "book_type_id",
            "library-a",
            "book-a",
            "Leesboek",
            "leesboek",
            "active"
        );
        $this->insertTerm(
            $this->tableNames->libraryBookTypes(),
            "book_type_id",
            "library-b",
            "book-b",
            "Kennisboek",
            "kennisboek",
            "active"
        );

        $this->insertContext("library-a", "shared-work", "book-a", 1);
        $this->insertContext("library-b", "shared-work", "book-b", 1);

        self::assertSame(2, $this->tableCount(
            $this->tableNames->libraryCatalogContexts()
        ));

        $this->assertDatabaseRejects(fn (): int|false => $this->insertContext(
            "library-a",
            "shared-work",
            "book-a",
            1
        ));
        $this->assertDatabaseRejects(fn (): int|false => $this->insertContext(
            "library-a",
            "other-work",
            "book-b",
            1
        ));
        $this->assertDatabaseRejects(fn (): int|false => $this->insertContext(
            "library-a",
            "other-work",
            "book-a",
            0
        ));
    }

    public function testJunctionsRejectCrossLibraryTermsAndDuplicates(): void
    {
        $this->insertLibrary("library-a");
        $this->insertLibrary("library-b");
        $this->insertWork("shared-work");
        $this->insertTerm(
            $this->tableNames->libraryBookTypes(),
            "book_type_id",
            "library-a",
            "book-a",
            "Leesboek",
            "leesboek",
            "active"
        );
        $this->insertTerm(
            $this->tableNames->libraryGenres(),
            "genre_id",
            "library-a",
            "genre-a",
            "Fantasy",
            "fantasy",
            "active"
        );
        $this->insertTerm(
            $this->tableNames->libraryGenres(),
            "genre_id",
            "library-b",
            "genre-b",
            "Avontuur",
            "avontuur",
            "active"
        );
        $this->insertTerm(
            $this->tableNames->librarySubjects(),
            "subject_id",
            "library-b",
            "subject-b",
            "Geschiedenis",
            "geschiedenis",
            "active"
        );
        $this->insertContext("library-a", "shared-work", "book-a", 1);

        $genres = $this->tableNames->libraryCatalogContextGenres();
        self::assertSame(1, $this->database->insert($genres, [
            "library_id" => "library-a",
            "work_id" => "shared-work",
            "genre_id" => "genre-a",
        ]));

        $this->assertDatabaseRejects(fn (): int|false =>
            $this->database->insert($genres, [
                "library_id" => "library-a",
                "work_id" => "shared-work",
                "genre_id" => "genre-a",
            ])
        );
        $this->assertDatabaseRejects(fn (): int|false =>
            $this->database->insert($genres, [
                "library_id" => "library-a",
                "work_id" => "shared-work",
                "genre_id" => "genre-b",
            ])
        );
        $this->assertDatabaseRejects(fn (): int|false =>
            $this->database->insert(
                $this->tableNames->libraryCatalogContextSubjects(),
                [
                    "library_id" => "library-a",
                    "work_id" => "shared-work",
                    "subject_id" => "subject-b",
                ]
            )
        );
    }

    private function insertLibrary(string $libraryId): int|false
    {
        return $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
    }

    private function insertWork(string $workId): int|false
    {
        return $this->database->insert($this->tableNames->works(), [
            "work_id" => $workId,
            "work_title" => "Integrity Work",
        ]);
    }

    private function insertTerm(
        string $table,
        string $idColumn,
        string $libraryId,
        string $termId,
        string $displayName,
        string $normalizedName,
        string $status
    ): int|false {
        return $this->database->insert($table, [
            "library_id" => $libraryId,
            $idColumn => $termId,
            "display_name" => $displayName,
            "normalized_name" => $normalizedName,
            "term_status" => $status,
            "seed_key" => null,
        ]);
    }

    private function insertContext(
        string $libraryId,
        string $workId,
        string $bookTypeId,
        int $version
    ): int|false {
        return $this->database->insert(
            $this->tableNames->libraryCatalogContexts(),
            [
                "library_id" => $libraryId,
                "work_id" => $workId,
                "book_type_id" => $bookTypeId,
                "context_version" => $version,
            ]
        );
    }

    private function assertDatabaseRejects(Closure $write): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            self::assertFalse($write());
            self::assertNotSame("", trim($this->database->last_error));
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
