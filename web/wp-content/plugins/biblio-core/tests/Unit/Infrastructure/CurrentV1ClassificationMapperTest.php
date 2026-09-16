<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\Classification\ClassificationNormalizedName;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\CurrentV1ClassificationMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1ClassificationMappingReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMappingReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedClassificationContract;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use PHPUnit\Framework\TestCase;

final class CurrentV1ClassificationMapperTest extends TestCase
{
    private const MANIFEST = "0000000000000000000000000000000000000000000000000000000000000000";

    public function testAllSevenReviewedBookTypeMappingsAreExplicit(): void
    {
        $values = [
            "Leesboek" => "book-reading",
            "Kennisboek" => "book-knowledge",
            "Kookboek" => "book-cook",
            "Stripboek" => "book-comic",
            "Studieboek" => "book-study",
            "Jeugdboek" => "book-reading",
            "Kinderboek" => "book-reading",
        ];
        $books = [];
        foreach (array_keys($values) as $index => $raw) {
            $books[] = $this->book("book-{$index}", $raw);
        }

        $result = $this->map($books);

        foreach ($books as $index => $book) {
            self::assertSame(
                $values[$book->payload()["bookType"]],
                $result->selection("book-{$index}")?->bookTypeId()->value()
            );
        }
        self::assertSame(
            "book-reading",
            $result->selection("book-6")?->bookTypeId()->value(),
            "Kinderboek must not infer Prentenboek."
        );
    }

    public function testOnlyApprovedExactGenresBecomeTypedTargets(): void
    {
        $book = $this->book(
            "book-a",
            "Leesboek",
            ["Fantasy", "Sciencefiction", "Thriller", "Romantiek"]
        );
        $result = $this->map([$book]);
        $selection = $result->selection("book-a");

        self::assertNotNull($selection);
        self::assertSame(
            ["genre-fantasy", "genre-science-fiction", "genre-thriller"],
            array_map(static fn ($id): string => $id->value(), $selection->genreIds())
        );
        self::assertContains(
            CurrentV1ClassificationMappingReason::GenreAssignmentPreserved->value,
            $this->reasons($result->findings())
        );
    }

    public function testSourceReviewAndNoSignalRemainBlocked(): void
    {
        $result = $this->map([
            $this->book("ready", "Leesboek", [], null),
            $this->book("migrate", "Leesboek", [], "migrate"),
            $this->book("review", "Leesboek", [], "review"),
            $this->book("no-signal", "Leesboek", [], "no_signal"),
        ]);

        self::assertNotNull($result->selection("ready"));
        self::assertNotNull($result->selection("migrate"));
        self::assertNull($result->selection("review"));
        self::assertNull($result->selection("no-signal"));
        self::assertSame(2, count(array_filter(
            $this->reasons($result->findings()),
            static fn (string $reason): bool => $reason ===
                CurrentV1ClassificationMappingReason::SourceReviewBlocked->value
        )));
    }

    public function testPerBookApprovalRequiresExactIdAndPayloadHash(): void
    {
        $book = $this->book(
            "reviewed-book",
            "Leesboek",
            ["Fantasy"],
            "review"
        );
        $contract = new CurrentV1ReviewedClassificationContract(
            self::MANIFEST,
            [
                "reviewed-book" => [
                    "payload_hash" => $book->payloadHash(),
                    "source_book_type_value" => "Leesboek",
                    "source_book_type_review_state" => "review",
                    "assignment_decision_status" => "REVIEW_MAPPING",
                    "book_type_seed_key" => "book_type.knowledge_book",
                    "genre_seed_keys" => ["genre.thriller"],
                    "decision_provenance" => "D-MIG-CLASS-MAP-01:test",
                ],
            ]
        );
        self::assertNotSame(
            (new CurrentV1ReviewedClassificationContract(self::MANIFEST))->identity(),
            $contract->identity()
        );

        $approved = $this->map([$book], contract: $contract);
        self::assertSame(
            "book-knowledge",
            $approved->selection("reviewed-book")?->bookTypeId()->value()
        );
        self::assertSame(
            ["genre-thriller"],
            array_map(
                static fn ($id): string => $id->value(),
                $approved->selection("reviewed-book")?->genreIds() ?? []
            )
        );

        $stale = new CurrentV1ReviewedClassificationContract(
            self::MANIFEST,
            [
                "reviewed-book" => [
                    "payload_hash" => str_repeat("f", 64),
                    "source_book_type_value" => "Leesboek",
                    "source_book_type_review_state" => "review",
                    "assignment_decision_status" => "REVIEW_MAPPING",
                    "book_type_seed_key" => "book_type.knowledge_book",
                    "genre_seed_keys" => ["genre.thriller"],
                    "decision_provenance" => "D-MIG-CLASS-MAP-01:test",
                ],
            ]
        );
        self::assertNull(
            $this->map([$book], contract: $stale)->selection("reviewed-book")
        );

        $readyBook = $this->book("ready-book", "Leesboek");
        $irrelevant = new CurrentV1ReviewedClassificationContract(
            self::MANIFEST,
            [
                "ready-book" => [
                    "payload_hash" => $readyBook->payloadHash(),
                    "source_book_type_value" => "Leesboek",
                    "source_book_type_review_state" => "review",
                    "assignment_decision_status" => "EXACT_TARGET",
                    "book_type_seed_key" => "book_type.knowledge_book",
                    "genre_seed_keys" => [],
                    "decision_provenance" => "D-MIG-CLASS-MAP-01:test",
                ],
            ]
        );
        self::assertSame(
            "book-reading",
            $this->map([$readyBook], contract: $irrelevant)
                ->selection("ready-book")
                ?->bookTypeId()
                ->value(),
            "Per-Book approvals must not override activation-ready source facts."
        );
    }

    public function testPerBookApprovalShapeAndProvenanceFailClosed(): void
    {
        $this->expectException(ValidationException::class);
        new CurrentV1ReviewedClassificationContract(self::MANIFEST, [
            "reviewed-book" => [
                "payload_hash" => str_repeat("a", 64),
                "source_book_type_value" => "Leesboek",
                "source_book_type_review_state" => "review",
                "assignment_decision_status" => "EXACT_TARGET",
                "book_type_seed_key" => "book_type.reading_book",
                "genre_seed_keys" => [],
                "decision_provenance" => "",
            ],
        ]);
    }

    public function testUnknownSourceReviewStateFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->map([$this->book("unknown-review", "Leesboek", [], "approved")]);
    }

    public function testConvergedSelectionsMustBeIdenticalAndNeverUseRecordOrder(): void
    {
        $books = [
            $this->book("book-z", "Leesboek", ["Fantasy"]),
            $this->book("book-a", "Kennisboek", ["Fantasy"]),
        ];
        $result = $this->map(
            $books,
            ["book-z" => "book-a", "book-a" => "book-a"]
        );

        self::assertNull($result->selection("book-z"));
        self::assertNull($result->selection("book-a"));
        self::assertSame(1, count(array_filter(
            $this->reasons($result->findings()),
            static fn (string $reason): bool => $reason ===
                CurrentV1ClassificationMappingReason::ConvergedConflict->value
        )));
        $memberFindings = array_values(array_filter(
            $result->findings(),
            static fn ($finding): bool => $finding->reasonCode() ===
                CurrentV1ClassificationMappingReason::ConvergedConflictMember->value
        ));
        self::assertCount(2, $memberFindings);
        self::assertSame(
            [
                "book-a:book-a:" . $books[1]->payloadHash(),
                "book-a:book-z:" . $books[0]->payloadHash(),
            ],
            array_map(
                static fn ($finding): string => $finding->sourceId(),
                $memberFindings
            )
        );
    }

    public function testCompatibleConvergenceDoesNotTransferSourceReviewBlock(): void
    {
        $books = [
            $this->book("reviewed-source", "Leesboek", ["Fantasy"], "no_signal"),
            $this->book("exact-source", "Leesboek", ["Fantasy"]),
        ];
        $result = $this->map(
            $books,
            ["reviewed-source" => "exact-source", "exact-source" => "exact-source"]
        );

        self::assertNull($result->selection("reviewed-source"));
        self::assertNotNull($result->selection("exact-source"));
        self::assertNotContains(
            CurrentV1ClassificationMappingReason::ConvergedConflict->value,
            $this->reasons($result->findings())
        );
    }

    public function testUnknownBookTypeAndInactiveTargetFailClosed(): void
    {
        $unknown = $this->map([$this->book("unknown", "Prentenachtig")]);
        self::assertNull($unknown->selection("unknown"));
        self::assertContains(
            CurrentV1ClassificationMappingReason::UnknownBookTypeBlocked->value,
            $this->reasons($unknown->findings())
        );

        $bookTypes = $this->bookTypeRepository();
        $bookTypes->statusOverrides["book_type.reading_book"] =
            ClassificationTermStatus::Inactive;
        $this->expectException(MigrationRunnerFailure::class);
        $this->map([$this->book("inactive", "Leesboek")], [], $bookTypes);
    }

    public function testMissingAndForeignTargetsFailClosed(): void
    {
        $missing = $this->bookTypeRepository();
        unset($missing->idsBySeed["book_type.reading_book"]);
        try {
            $this->map([$this->book("missing", "Leesboek")], [], $missing);
            self::fail("Missing approved target must fail closed.");
        } catch (MigrationRunnerFailure) {
        }

        $foreign = $this->bookTypeRepository();
        $foreign->libraryOverrides["book_type.reading_book"] = "library-foreign";
        $this->expectException(MigrationRunnerFailure::class);
        $this->map([$this->book("foreign", "Leesboek")], [], $foreign);
    }

    public function testCatalogClassificationDependencyClosesWhileItemLocalRemainsOpen(): void
    {
        $book = new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "book-a", [
            ...$this->book("book-a", "Leesboek", ["Fantasy"])->payload(),
            "title" => "Synthetic",
            "isbn" => "9780306406157",
            "isbn10" => "",
            "isbn13" => "",
            "editionFormat" => "standaard",
            "variantOfBookId" => "",
            "containedWorks" => [],
        ]);
        $copy = new MigrationSourceRecord(CurrentV1SourceAdapter::COPY, "copy-a", [
            "id" => "copy-a",
            "bookId" => "book-a",
            "copyNumber" => "",
            "legacyBookNumber" => "",
            "sourceBookNumber" => "",
        ]);
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::MANIFEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            [$book, $copy],
            []
        );
        $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("user-a"),
            new LibraryId("library-a"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
        $result = (new CurrentV1CatalogMapper(
            classificationMapper: $this->classificationMapper()
        ))->map($inspection, $target);
        $reasons = $this->reasons($result->findings());

        self::assertNotContains(
            CurrentV1CatalogMappingReason::UnresolvedClassificationDependency->value,
            $reasons
        );
        self::assertContains(
            CurrentV1CatalogMappingReason::UnresolvedItemLocalDependency->value,
            $reasons
        );
        self::assertContains(
            CurrentV1ClassificationMappingReason::ClassificationReady->value,
            $reasons
        );
        self::assertCount(2, $result->records(), "No Item plan exists before Item-local review.");
    }

    /**
     * @param list<MigrationSourceRecord> $books
     * @param array<string,string> $representatives
     */
    private function map(
        array $books,
        array $representatives = [],
        ?ClassificationBookTypeRepositoryStub $bookTypes = null,
        ?CurrentV1ReviewedClassificationContract $contract = null
    ): \Biblio\Core\Infrastructure\Migration\CurrentV1ClassificationMappingResult {
        $byKey = [];
        $candidates = [];
        foreach ($books as $book) {
            $byKey["source:" . $book->sourceId()] = $book;
            $candidates[$book->sourceId()] = true;
            $representatives[$book->sourceId()] ??= $book->sourceId();
        }
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::MANIFEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $books,
            []
        );
        $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("user-a"),
            new LibraryId("library-a"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
        $mapper = $this->classificationMapper($bookTypes, $contract);

        return $mapper->map(
            $inspection,
            $target,
            $byKey,
            $representatives,
            $candidates
        );
    }

    /** @param list<string> $genres */
    private function book(
        string $id,
        string $bookType,
        array $genres = [],
        ?string $reviewState = null
    ): MigrationSourceRecord {
        $payload = [
            "id" => $id,
            "bookType" => $bookType,
            "categories" => [],
            "genres" => $genres,
        ];
        if ($reviewState !== null) {
            $payload["taxonomyMeta"] = [
                "bookType" => ["migration" => ["status" => $reviewState]],
            ];
        }
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, $payload);
    }

    private function bookTypeRepository(): ClassificationBookTypeRepositoryStub
    {
        return new ClassificationBookTypeRepositoryStub([
            "book_type.reading_book" => "book-reading",
            "book_type.knowledge_book" => "book-knowledge",
            "book_type.cookbook" => "book-cook",
            "book_type.comic_book" => "book-comic",
            "book_type.study_book" => "book-study",
        ]);
    }

    private function genreRepository(): ClassificationGenreRepositoryStub
    {
        return new ClassificationGenreRepositoryStub([
            "genre.fantasy" => "genre-fantasy",
            "genre.science_fiction" => "genre-science-fiction",
            "genre.thriller" => "genre-thriller",
        ]);
    }

    private function classificationMapper(
        ?ClassificationBookTypeRepositoryStub $bookTypes = null,
        ?CurrentV1ReviewedClassificationContract $contract = null
    ): CurrentV1ClassificationMapper {
        return new CurrentV1ClassificationMapper(
            $bookTypes ?? $this->bookTypeRepository(),
            $this->genreRepository(),
            $contract ?? new CurrentV1ReviewedClassificationContract(self::MANIFEST)
        );
    }

    /** @param list<\Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding> $findings */
    private function reasons(array $findings): array
    {
        return array_map(static fn ($finding): string => $finding->reasonCode(), $findings);
    }
}

final class ClassificationBookTypeRepositoryStub implements LibraryBookTypeRepository
{
    /** @var array<string,string> */
    public array $idsBySeed;

    /** @var array<string,ClassificationTermStatus> */
    public array $statusOverrides = [];

    /** @var array<string,string> */
    public array $libraryOverrides = [];

    /** @param array<string,string> $idsBySeed */
    public function __construct(array $idsBySeed)
    {
        $this->idsBySeed = $idsBySeed;
    }

    public function find(LibraryId $libraryId, LibraryBookTypeId $id): ?LibraryBookType
    {
        unset($libraryId, $id);
        return null;
    }

    public function findForUpdate(LibraryId $libraryId, LibraryBookTypeId $id): ?LibraryBookType
    {
        return $this->find($libraryId, $id);
    }

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryBookType {
        unset($libraryId, $name);
        return null;
    }

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryBookType {
        $seed = $seedKey->value();
        $id = $this->idsBySeed[$seed] ?? null;
        return $id === null ? null : new LibraryBookType(
            new LibraryId($this->libraryOverrides[$seed] ?? $libraryId->value()),
            new LibraryBookTypeId($id),
            new ClassificationTermName($id),
            new ClassificationNormalizedName($id),
            $this->statusOverrides[$seed] ?? ClassificationTermStatus::Active,
            $seedKey
        );
    }

    public function countActive(LibraryId $libraryId): int
    {
        unset($libraryId);
        return count($this->idsBySeed);
    }
}

final class ClassificationGenreRepositoryStub implements LibraryGenreRepository
{
    /** @param array<string,string> $idsBySeed */
    public function __construct(private array $idsBySeed)
    {
    }

    public function find(LibraryId $libraryId, LibraryGenreId $id): ?LibraryGenre
    {
        unset($libraryId, $id);
        return null;
    }

    public function findForUpdate(LibraryId $libraryId, LibraryGenreId $id): ?LibraryGenre
    {
        return $this->find($libraryId, $id);
    }

    public function findByNormalizedName(
        LibraryId $libraryId,
        ClassificationNormalizedName $name
    ): ?LibraryGenre {
        unset($libraryId, $name);
        return null;
    }

    public function findBySeedKey(
        LibraryId $libraryId,
        ClassificationSeedKey $seedKey
    ): ?LibraryGenre {
        $id = $this->idsBySeed[$seedKey->value()] ?? null;
        return $id === null ? null : new LibraryGenre(
            $libraryId,
            new LibraryGenreId($id),
            new ClassificationTermName($id),
            new ClassificationNormalizedName($id),
            ClassificationTermStatus::Active,
            $seedKey
        );
    }
}
