<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Catalog\ContributorRole;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1AuthorMapper,
    CurrentV1AuthorMappingReason,
    CurrentV1AuthorMappingResult,
    CurrentV1AuthorSourceIds,
    CurrentV1CatalogSourceIds,
    CurrentV1ReviewedAuthorContract,
    CurrentV1SourceAdapter
};
use PHPUnit\Framework\TestCase;

final class CurrentV1AuthorMapperTest extends TestCase
{
    private const MANIFEST =
        "0000000000000000000000000000000000000000000000000000000000000000";

    public function testStableAuthorIsOnePlanAcrossSeveralBooks(): void
    {
        $result = $this->map(
            [$this->author("author-a", "Alex Example", true)],
            [
                $this->book("book-a", ["Alex Example"], ["author-a"]),
                $this->book("book-b", ["Alex Example"], ["author-a"]),
            ]
        );

        self::assertCount(1, $result->authorPlans());
        self::assertCount(2, $result->contributorPlans());
        $plan = $result->authorPlans()[0]->typedPlan();
        self::assertInstanceOf(CatalogAuthorPlan::class, $plan);
        self::assertSame("Alex Example", $plan->displayName());
        self::assertNull($plan->approvedExistingAuthorId());
        self::assertContains(
            CurrentV1AuthorMappingReason::AuthorSourceEvidenceRetained->value,
            $this->reasons($result)
        );
    }

    public function testEqualNamesUnderDifferentStableIdsRemainDistinct(): void
    {
        $result = $this->map(
            [
                $this->author("author-a", "Alex Example"),
                $this->author("author-b", "Alex Example"),
            ],
            [
                $this->book("book-a", ["Alex Example"], ["author-a"]),
                $this->book("book-b", ["Alex Example"], ["author-b"]),
            ]
        );
        $ids = array_map(
            static fn (MigrationSourceRecord $record): string => $record->sourceId(),
            $result->authorPlans()
        );

        self::assertSame([
            CurrentV1AuthorSourceIds::stableAuthor("author-a"),
            CurrentV1AuthorSourceIds::stableAuthor("author-b"),
        ], $ids);
    }

    public function testIdlessIdentityIsOccurrenceScopedAndDeterministic(): void
    {
        $books = [
            $this->book("book-a", ["Same Name", "Same Name"]),
            $this->book("book-b", ["Same Name"]),
        ];
        $first = $this->map([], $books);
        $second = $this->map([], $books);
        $ids = array_map(
            static fn (MigrationSourceRecord $record): string => $record->sourceId(),
            $first->authorPlans()
        );

        self::assertCount(3, array_unique($ids));
        self::assertSame(
            $ids,
            array_map(
                static fn (MigrationSourceRecord $record): string => $record->sourceId(),
                $second->authorPlans()
            )
        );
    }

    public function testStablePrefixAndIdlessTailPreserveRoleAndPosition(): void
    {
        $result = $this->map(
            [$this->author("author-a", "Stable Person")],
            [$this->book(
                "book-a",
                ["Stable Person", "Independent Person", "Third Person"],
                ["author-a"]
            )]
        );
        $plans = array_map(
            static fn (MigrationSourceRecord $record): CatalogWorkContributorPlan =>
                $record->typedPlan(),
            $result->contributorPlans()
        );

        self::assertSame([1, 2, 3], array_map(
            static fn (CatalogWorkContributorPlan $plan): int =>
                $plan->position()->value(),
            $plans
        ));
        self::assertSame(
            [ContributorRole::Author, ContributorRole::Author, ContributorRole::Author],
            array_map(
                static fn (CatalogWorkContributorPlan $plan): ContributorRole =>
                    $plan->role(),
                $plans
            )
        );
        self::assertCount(3, $result->authorPlans());
    }

    public function testShiftedPrefixFailsClosedWithoutNameRepair(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->map(
            [
                $this->author("author-a", "First Person"),
                $this->author("author-b", "Second Person"),
            ],
            [$this->book(
                "book-a",
                ["Second Person", "First Person"],
                ["author-a", "author-b"]
            )]
        );
    }

    public function testCaseOnlyOccurrenceUsesStableAuthorAndKeepsOccurrenceSpelling(): void
    {
        $result = $this->map(
            [$this->author("author-a", "Case Person")],
            [$this->book("book-a", ["CASE PERSON"], ["author-a"])]
        );
        $author = $result->authorPlans()[0]->typedPlan();
        $contributor = $result->contributorPlans()[0]->typedPlan();

        self::assertInstanceOf(CatalogAuthorPlan::class, $author);
        self::assertInstanceOf(CatalogWorkContributorPlan::class, $contributor);
        self::assertSame("Case Person", $author->displayName());
        self::assertSame("CASE PERSON", $contributor->observedDisplayName());
        self::assertSame(
            CurrentV1AuthorSourceIds::stableAuthor("author-a"),
            $contributor->authorSourceId()
        );
    }

    public function testInactiveEarlierOccurrenceDoesNotCompactLaterPosition(): void
    {
        $book = $this->book("book-a", ["Placeholder", "Active Person"]);
        $contract = $this->contract(
            occurrenceExceptions: [
                CurrentV1AuthorSourceIds::occurrence("book-a", 1) =>
                    CurrentV1AuthorMappingReason::InvalidAuthorPlaceholder,
            ]
        );
        $result = $this->map([], [$book], contract: $contract);
        $plan = $result->contributorPlans()[0]->typedPlan();

        self::assertInstanceOf(CatalogWorkContributorPlan::class, $plan);
        self::assertSame(2, $plan->position()->value());
        self::assertContains(
            CurrentV1AuthorMappingReason::InvalidAuthorPlaceholder->value,
            $this->reasons($result)
        );
    }

    public function testAliasOccurrencesRemainDistinctAndConvergeOnOneEdgeIntent(): void
    {
        $result = $this->map(
            [$this->author("author-a", "Alias Person")],
            [
                $this->book("book-a", ["Alias Person"], ["author-a"]),
                $this->book("book-z", ["Alias Person"], ["author-a"]),
            ],
            ["book-a" => "book-a", "book-z" => "book-a"]
        );
        $records = $result->contributorPlans();
        $plans = array_map(
            static fn (MigrationSourceRecord $record): CatalogWorkContributorPlan =>
                $record->typedPlan(),
            $records
        );

        self::assertCount(2, $records);
        self::assertNotSame($records[0]->sourceId(), $records[1]->sourceId());
        self::assertSame(
            [
                CurrentV1CatalogSourceIds::work("book-a"),
                CurrentV1CatalogSourceIds::work("book-z"),
            ],
            array_map(
                static fn (CatalogWorkContributorPlan $plan): string =>
                    $plan->workSourceId(),
                $plans
            )
        );
        self::assertCount(1, $result->convergences());
    }

    public function testAliasIdentityConflictProducesNoContributorEdgePlan(): void
    {
        $result = $this->map(
            [
                $this->author("author-a", "First Person"),
                $this->author("author-b", "Second Person"),
            ],
            [
                $this->book("book-a", ["First Person"], ["author-a"]),
                $this->book("book-z", ["Second Person"], ["author-b"]),
            ],
            ["book-a" => "book-a", "book-z" => "book-a"]
        );

        self::assertSame([], $result->contributorPlans());
        self::assertContains(
            CurrentV1AuthorMappingReason::AliasContributorConflict->value,
            $this->reasons($result)
        );
    }

    public function testDisjointPositiveAliasSetsCannotBeSilentlyUnioned(): void
    {
        $result = $this->map(
            [
                $this->author("author-a", "First Person"),
                $this->author("author-b", "Second Person"),
            ],
            [
                $this->book("book-a", ["First Person"], ["author-a"]),
                $this->book(
                    "book-z",
                    ["First Person", "Second Person"],
                    ["author-a", "author-b"]
                ),
            ],
            ["book-a" => "book-a", "book-z" => "book-a"]
        );

        self::assertCount(2, $result->contributorPlans());
        self::assertSame(
            [
                CurrentV1AuthorSourceIds::occurrence("book-a", 1),
                CurrentV1AuthorSourceIds::occurrence("book-z", 1),
            ],
            array_map(
                static fn (MigrationSourceRecord $record): string =>
                    $record->sourceId(),
                $result->contributorPlans()
            )
        );
        self::assertCount(1, $result->convergences());
        self::assertContains(
            CurrentV1AuthorMappingReason::AliasContributorConflict->value,
            $this->reasons($result)
        );
    }

    public function testQuarantinedWorkKeepsStableAuthorButCreatesNoOrphanIdlessAuthor(): void
    {
        $result = $this->map(
            [$this->author("author-a", "Stable Person")],
            [
                $this->book("blocked-stable", ["Stable Person"], ["author-a"]),
                $this->book("blocked-idless", ["Independent Person"]),
            ],
            []
        );

        self::assertCount(1, $result->authorPlans());
        self::assertSame([], $result->contributorPlans());
        self::assertCount(2, $result->blockedDependencies());
    }

    public function testReviewedMalformedAndCorporateCasesHaveTerminalAccounting(): void
    {
        $books = [
            $this->book("placeholder", ["Synthetic Placeholder"]),
            $this->book("compound", ["First Person; Second Person"]),
            $this->book("malformed", ["Synthetic &Malformed"]),
            $this->book("collective", ["Synthetic Collective"]),
            $this->book("stable-collective", ["Synthetic Publisher"], ["corp"]),
        ];
        $contract = $this->contract(
            authorExceptions: [
                "corp" => CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
            ],
            occurrenceExceptions: [
                CurrentV1AuthorSourceIds::occurrence("placeholder", 1) =>
                    CurrentV1AuthorMappingReason::InvalidAuthorPlaceholder,
                CurrentV1AuthorSourceIds::occurrence("compound", 1) =>
                    CurrentV1AuthorMappingReason::CompoundAuthorScalar,
                CurrentV1AuthorSourceIds::occurrence("malformed", 1) =>
                    CurrentV1AuthorMappingReason::MalformedAuthorScalar,
                CurrentV1AuthorSourceIds::occurrence("collective", 1) =>
                    CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
                CurrentV1AuthorSourceIds::occurrence("stable-collective", 1) =>
                    CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
            ]
        );
        $result = $this->map(
            [$this->author("corp", "Synthetic Publisher")],
            $books,
            contract: $contract
        );

        self::assertSame([], $result->records());
        self::assertCount(3, $result->quarantineFindings());
        self::assertCount(3, $result->preservationFindings());
    }

    public function testContainedWorkAuthorIsPreservedWithoutPlan(): void
    {
        $book = $this->book("book-a", []);
        $payload = $book->payload();
        $payload["containedWorks"] = [[
            "title" => "Synthetic child",
            "author" => "Synthetic child person",
        ]];
        $result = $this->map([], [new MigrationSourceRecord(
            CurrentV1SourceAdapter::BOOK,
            "book-a",
            $payload
        )]);

        self::assertSame([], $result->records());
        self::assertContains(
            CurrentV1AuthorMappingReason::ContainedWorkAuthorPreserved->value,
            $this->reasons($result)
        );
    }

    /**
     * @param list<MigrationSourceRecord> $authors
     * @param list<MigrationSourceRecord> $books
     * @param null|array<string,string> $representatives
     */
    private function map(
        array $authors,
        array $books,
        ?array $representatives = null,
        ?CurrentV1ReviewedAuthorContract $contract = null
    ): CurrentV1AuthorMappingResult {
        $records = [...$authors, ...$books];
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::MANIFEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $records,
            []
        );
        $authorMap = [];
        foreach ($authors as $author) {
            $authorMap["source:" . $author->sourceId()] = $author;
        }
        $bookMap = [];
        foreach ($books as $book) {
            $bookMap["source:" . $book->sourceId()] = $book;
        }
        if ($representatives === null) {
            $representatives = [];
            foreach ($books as $book) {
                $representatives[$book->sourceId()] = $book->sourceId();
            }
        }

        return (new CurrentV1AuthorMapper(
            $contract ?? $this->contract()
        ))->map($inspection, $authorMap, $bookMap, $representatives);
    }

    private function author(
        string $id,
        string $name,
        bool $withEvidence = false
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::AUTHOR, $id, [
            "id" => $id,
            "displayName" => $name,
            ...($withEvidence ? ["sortName" => "Example, Alex"] : []),
        ]);
    }

    /** @param list<string> $names @param list<string> $authorIds */
    private function book(
        string $id,
        array $names,
        array $authorIds = []
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "authors" => $names,
            "authorIds" => $authorIds,
            "containedWorks" => [],
        ]);
    }

    /**
     * @param array<string,CurrentV1AuthorMappingReason> $authorExceptions
     * @param array<string,CurrentV1AuthorMappingReason> $occurrenceExceptions
     */
    private function contract(
        array $authorExceptions = [],
        array $occurrenceExceptions = []
    ): CurrentV1ReviewedAuthorContract {
        return new CurrentV1ReviewedAuthorContract(
            self::MANIFEST,
            $authorExceptions,
            $occurrenceExceptions
        );
    }

    /** @return list<string> */
    private function reasons(CurrentV1AuthorMappingResult $result): array
    {
        return array_map(
            static fn ($finding): string => $finding->reasonCode(),
            $result->findings()
        );
    }
}
