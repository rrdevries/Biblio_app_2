<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermConflictType;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubject;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibrarySubjectRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;

final class ClassificationPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testTypedTermRepositoriesRoundTripAndPreserveSeedIdentity(): void
    {
        $libraryId = $this->addLibrary("library-a");
        $otherLibraryId = $this->addLibrary("library-b");
        $bookType = $this->bookType($libraryId, "book-a", "Leesboek");
        $genre = $this->genre(
            $libraryId,
            "genre-a",
            "Fantasy",
            "genre.fantasy"
        );
        $subject = $this->subject($libraryId, "subject-a", "Geschiedenis");

        $this->bookTypes()->add($bookType);
        $this->genres()->add($genre);
        $this->genres()->add($this->genre(
            $otherLibraryId,
            "genre-a",
            "Fantasy",
            "genre.fantasy"
        ));
        $this->subjects()->add($subject);

        $storedBookType = $this->bookTypes()->find($libraryId, $bookType->id());
        $storedGenre = $this->genres()->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey("genre.fantasy")
        );
        $storedSubject = $this->subjects()->findByNormalizedName(
            $libraryId,
            $subject->normalizedName()
        );

        self::assertInstanceOf(LibraryBookType::class, $storedBookType);
        self::assertSame("Leesboek", $storedBookType->name()->value());
        self::assertInstanceOf(LibraryGenre::class, $storedGenre);
        self::assertSame("genre-a", $storedGenre->id()->value());
        self::assertSame(
            "genre.fantasy",
            $storedGenre->seedKey()?->value()
        );
        self::assertInstanceOf(LibrarySubject::class, $storedSubject);
        self::assertSame("subject-a", $storedSubject->id()->value());
        self::assertSame(
            "library-b",
            $this->genres()->find($otherLibraryId, $genre->id())
                ?->libraryId()->value()
        );

        $renamed = new ClassificationTermName("Fantastiek");
        $this->genres()->rename(
            $libraryId,
            $genre->id(),
            $renamed,
            $this->normalizer()->normalize($renamed)
        );
        $this->genres()->changeStatus(
            $libraryId,
            $genre->id(),
            ClassificationTermStatus::Inactive
        );
        self::assertTrue($this->subjects()->adoptSeedKey(
            $libraryId,
            $subject->id(),
            new ClassificationSeedKey("subject.history")
        ));
        self::assertFalse($this->subjects()->adoptSeedKey(
            $libraryId,
            $subject->id(),
            new ClassificationSeedKey("subject.other")
        ));

        $storedGenre = $this->transaction()->run(
            fn (): ?LibraryGenre => $this->genres()->findForUpdate(
                $libraryId,
                $genre->id()
            )
        );
        self::assertNotNull($storedGenre);
        self::assertSame("Fantastiek", $storedGenre->name()->value());
        self::assertSame(
            ClassificationTermStatus::Inactive,
            $storedGenre->status()
        );
        self::assertSame(
            "genre.fantasy",
            $storedGenre->seedKey()?->value(),
            "Rename and lifecycle writes must not alter the immutable seed key."
        );
    }

    public function testTermDuplicateConflictsAreStableAndTyped(): void
    {
        $libraryId = $this->addLibrary("library-a");
        $this->genres()->add($this->genre(
            $libraryId,
            "genre-a",
            "Sci-Fi",
            "genre.science_fiction"
        ));

        try {
            $this->genres()->add($this->genre(
                $libraryId,
                "genre-b",
                "Sci Fi",
                null,
                ClassificationTermStatus::Inactive
            ));
            self::fail("Normalized duplicate was accepted.");
        } catch (ClassificationTermConflict $exception) {
            self::assertSame(
                ClassificationTermConflictType::NormalizedName,
                $exception->conflictType()
            );
        }

        $renameTarget = $this->genre(
            $libraryId,
            "genre-rename",
            "Thriller"
        );
        $this->genres()->add($renameTarget);
        $conflictingName = new ClassificationTermName("SCI FI");

        try {
            $this->genres()->rename(
                $libraryId,
                $renameTarget->id(),
                $conflictingName,
                $this->normalizer()->normalize($conflictingName)
            );
            self::fail("Conflicting rename was accepted.");
        } catch (ClassificationTermConflict $exception) {
            self::assertSame(
                ClassificationTermConflictType::NormalizedName,
                $exception->conflictType()
            );
            self::assertSame(
                "Thriller",
                $this->genres()->find(
                    $libraryId,
                    $renameTarget->id()
                )?->name()->value()
            );
        }

        try {
            $this->genres()->add($this->genre(
                $libraryId,
                "genre-c",
                "Sciencefiction",
                "genre.science_fiction"
            ));
            self::fail("Duplicate seed key was accepted.");
        } catch (ClassificationTermConflict $exception) {
            self::assertSame(
                ClassificationTermConflictType::SeedKey,
                $exception->conflictType()
            );
        }
    }

    public function testContextRepositoryRoundTripCasSetDeltaAndRollback(): void
    {
        $libraryA = $this->addLibrary("library-a");
        $libraryB = $this->addLibrary("library-b");
        $workId = new WorkId("work-a");
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work($workId, "Context Work")
        );
        $bookA = $this->bookType($libraryA, "book-a", "Leesboek");
        $bookA2 = $this->bookType($libraryA, "book-a2", "Kennisboek");
        $genreA = $this->genre($libraryA, "genre-a", "Fantasy");
        $genreA2 = $this->genre($libraryA, "genre-a2", "Avontuur");
        $genreB = $this->genre($libraryB, "genre-b", "Thriller");
        $subjectA = $this->subject($libraryA, "subject-a", "Geschiedenis");

        foreach ([$bookA, $bookA2] as $bookType) {
            $this->bookTypes()->add($bookType);
        }
        foreach ([$genreA, $genreA2, $genreB] as $genre) {
            $this->genres()->add($genre);
        }
        $this->subjects()->add($subjectA);

        $initial = LibraryCatalogContext::create(
            $libraryA,
            $workId,
            new LibraryCatalogSelection(
                $bookA->id(),
                [$genreA->id()],
                [$subjectA->id()]
            )
        );
        $this->transaction()->run(fn () => $this->contexts()->add($initial));

        $stored = $this->contexts()->find($libraryA, $workId);
        self::assertNotNull($stored);
        self::assertSame(1, $stored->version()->value());
        self::assertTrue($stored->classification()->equals(
            $initial->classification()
        ));

        $replacement = new LibraryCatalogContext(
            $libraryA,
            $workId,
            new LibraryCatalogSelection(
                $bookA2->id(),
                [$genreA2->id()],
                []
            ),
            new LibraryCatalogContextVersion(2)
        );
        self::assertTrue($this->transaction()->run(
            fn (): bool => $this->contexts()->replaceIfVersionMatches(
                $replacement,
                new LibraryCatalogContextVersion(1)
            )
        ));

        $stored = $this->contexts()->find($libraryA, $workId);
        self::assertNotNull($stored);
        self::assertSame(2, $stored->version()->value());
        self::assertTrue($stored->classification()->equals(
            $replacement->classification()
        ));
        self::assertFalse($this->transaction()->run(
            fn (): bool => $this->contexts()->replaceIfVersionMatches(
                $replacement,
                new LibraryCatalogContextVersion(1)
            )
        ));

        $invalidCrossLibraryReplacement = new LibraryCatalogContext(
            $libraryA,
            $workId,
            new LibraryCatalogSelection($bookA2->id(), [$genreB->id()]),
            new LibraryCatalogContextVersion(3)
        );

        try {
            $this->transaction()->run(
                fn (): bool => $this->contexts()->replaceIfVersionMatches(
                    $invalidCrossLibraryReplacement,
                    new LibraryCatalogContextVersion(2)
                )
            );
            self::fail("Cross-Library context replacement was accepted.");
        } catch (PersistenceException) {
            $afterRollback = $this->contexts()->find($libraryA, $workId);
            self::assertNotNull($afterRollback);
            self::assertSame(2, $afterRollback->version()->value());
            self::assertTrue($afterRollback->classification()->equals(
                $replacement->classification()
            ));
        }

        try {
            $this->transaction()->run(
                fn () => $this->contexts()->add($initial)
            );
            self::fail("Duplicate context was accepted.");
        } catch (LibraryCatalogContextAlreadyExists) {
            self::assertSame(1, $this->tableCount(
                $this->tableNames->libraryCatalogContexts()
            ));
        }
    }

    public function testContextCompoundWritesRequireOwningTransaction(): void
    {
        $libraryId = $this->addLibrary("library-a");
        $workId = new WorkId("work-a");
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work($workId, "Context Work")
        );
        $bookType = $this->bookType($libraryId, "book-a", "Leesboek");
        $this->bookTypes()->add($bookType);

        $this->expectException(PersistenceException::class);
        $this->contexts()->add(LibraryCatalogContext::create(
            $libraryId,
            $workId,
            new LibraryCatalogSelection($bookType->id())
        ));
    }

    private function addLibrary(string $value): LibraryId
    {
        $id = new LibraryId($value);
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($id)
        );

        return $id;
    }

    private function bookType(
        LibraryId $libraryId,
        string $id,
        string $name
    ): LibraryBookType {
        $termName = new ClassificationTermName($name);

        return new LibraryBookType(
            $libraryId,
            new LibraryBookTypeId($id),
            $termName,
            $this->normalizer()->normalize($termName),
            ClassificationTermStatus::Active
        );
    }

    private function genre(
        LibraryId $libraryId,
        string $id,
        string $name,
        ?string $seedKey = null,
        ClassificationTermStatus $status = ClassificationTermStatus::Active
    ): LibraryGenre {
        $termName = new ClassificationTermName($name);

        return new LibraryGenre(
            $libraryId,
            new LibraryGenreId($id),
            $termName,
            $this->normalizer()->normalize($termName),
            $status,
            $seedKey === null ? null : new ClassificationSeedKey($seedKey)
        );
    }

    private function subject(
        LibraryId $libraryId,
        string $id,
        string $name
    ): LibrarySubject {
        $termName = new ClassificationTermName($name);

        return new LibrarySubject(
            $libraryId,
            new LibrarySubjectId($id),
            $termName,
            $this->normalizer()->normalize($termName),
            ClassificationTermStatus::Active
        );
    }

    private function normalizer(): ClassificationNameNormalizer
    {
        return ClassificationNameNormalizer::create();
    }

    private function bookTypes(): WpdbLibraryBookTypeRepository
    {
        return new WpdbLibraryBookTypeRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function genres(): WpdbLibraryGenreRepository
    {
        return new WpdbLibraryGenreRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function subjects(): WpdbLibrarySubjectRepository
    {
        return new WpdbLibrarySubjectRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function contexts(): WpdbLibraryCatalogContextRepository
    {
        return new WpdbLibraryCatalogContextRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function transaction(): WpdbTransactionManager
    {
        return new WpdbTransactionManager($this->database);
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
