<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\Assessments\{
    HistoricalRatingMigrationParticipant,
    HistoricalRatingPlan,
    HistoricalWrittenReviewMigrationParticipant,
    HistoricalWrittenReviewPlan
};
use Biblio\Core\Application\Migration\Notes\PrivateNoteMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourceMappingResult,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1AssessmentMapper,
    CurrentV1AssessmentMappingReason,
    CurrentV1CatalogMapper,
    CurrentV1CatalogSourceIds,
    CurrentV1ReviewedAssessmentContract,
    CurrentV1SourceAdapter
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1AssessmentMapperTest extends TestCase
{
    private const DIGEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testTypedRatingsReviewAndRestrictedReflectionsAreSeparated(): void
    {
        $reviewBody = "private-review-synthetic";
        $reflectionBodies = [];
        $books = [];
        foreach ([1, 3, 4, 5] as $index => $stars) {
            $books[] = $this->book(
                "rating-{$index}",
                $stars,
                $index === 0 ? [[
                    "date" => "2020-01-02T03:04:05.678Z",
                    "text" => $reviewBody,
                ]] : []
            );
        }
        for ($index = 0; $index < 5; ++$index) {
            $body = "private-reflection-synthetic-{$index}";
            $reflectionBodies[] = $body;
            $books[] = $this->book("reflection-{$index}", 0, [], $body);
        }

        $result = $this->map($books);
        $ratings = $this->plans($result, HistoricalRatingMigrationParticipant::SOURCE_TYPE);
        $reviews = $this->plans(
            $result,
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE
        );

        self::assertCount(4, $ratings);
        self::assertCount(1, $reviews);
        foreach ([1, 3, 4, 5] as $index => $stars) {
            $sourceId = CurrentV1CatalogSourceIds::rating("rating-{$index}");
            self::assertInstanceOf(HistoricalRatingPlan::class, $ratings[$sourceId]);
            self::assertSame((float) $stars, $ratings[$sourceId]->value()->stars());
            self::assertNull($ratings[$sourceId]->assessedAt());
            self::assertNull($ratings[$sourceId]->readingRoundSourceId());
            self::assertSame("target-user", $ratings[$sourceId]->targetUserId()->value());
            self::assertSame(
                CurrentV1CatalogSourceIds::work("rating-{$index}"),
                $ratings[$sourceId]->workSourceId()
            );
        }
        $reviewId = CurrentV1CatalogSourceIds::review("rating-0", 1);
        self::assertInstanceOf(HistoricalWrittenReviewPlan::class, $reviews[$reviewId]);
        self::assertSame($reviewBody, $reviews[$reviewId]->content()->value());
        self::assertSame(
            "2020-01-02T03:04:05.678000Z",
            $reviews[$reviewId]->canonicalPayload()["assessed_at"]
        );
        self::assertNull($reviews[$reviewId]->readingRoundSourceId());

        self::assertSame(5, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::ReflectionTargetNotAvailable
        ));
        $reflectionFindings = array_values(array_filter(
            $result->findings(),
            static fn ($finding): bool => $finding->reasonCode()
                === CurrentV1AssessmentMappingReason::ReflectionTargetNotAvailable->value
        ));
        foreach ($reflectionFindings as $finding) {
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/D',
                $finding->toArray()["evidence_hash"]
            );
        }
        $artifactProjection = json_encode(array_map(
            static fn ($finding): array => $finding->toArray(),
            $result->findings()
        ), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($reviewBody, $artifactProjection);
        self::assertStringNotContainsString("2020-01-02T03:04:05.678Z", $artifactProjection);
        foreach ($reflectionBodies as $body) {
            self::assertStringNotContainsString($body, $artifactProjection);
        }
        self::assertSame([], $this->plans($result, PrivateNoteMigrationParticipant::SOURCE_TYPE));
    }

    public function testZeroAndUnrelatedBookContentCreateNoAssessmentObservation(): void
    {
        $book = $this->book("empty", 0);
        $payload = $book->payload();
        $payload["notes"] = [["text" => "not an assessment"]];
        $payload["readStatus"] = "read";
        $result = $this->map([
            new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "empty", $payload),
        ]);

        self::assertSame([], $this->plans(
            $result,
            HistoricalRatingMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame([], $this->plans(
            $result,
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame(0, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::ReflectionTargetNotAvailable
        ));
    }

    public function testAliasCardinalityConflictsFailClosedForEqualOrDifferentRatings(): void
    {
        foreach ([[4, 4], [4, 5]] as [$first, $second]) {
            $result = $this->map([
                $this->book(
                    "alias-a",
                    $first,
                    isbn: "9780306406157",
                    title: "Shared synthetic Work"
                ),
                $this->book(
                    "alias-b",
                    $second,
                    isbn: "9780306406157",
                    title: "Shared synthetic Work"
                ),
            ]);
            self::assertSame([], $this->plans(
                $result,
                HistoricalRatingMigrationParticipant::SOURCE_TYPE
            ));
            self::assertSame(2, $this->reasonCount(
                $result,
                CurrentV1AssessmentMappingReason::ConvergedRatingConflict
            ));
        }
    }

    public function testSingleAliasObservationKeepsItsOwnExactWorkDependency(): void
    {
        $result = $this->map([
            $this->book(
                "alias-a",
                0,
                isbn: "9780306406157",
                title: "Shared synthetic Work"
            ),
            $this->book(
                "alias-b",
                4,
                isbn: "9780306406157",
                title: "Shared synthetic Work"
            ),
        ]);
        $ratings = $this->plans(
            $result,
            HistoricalRatingMigrationParticipant::SOURCE_TYPE
        );
        $sourceId = CurrentV1CatalogSourceIds::rating("alias-b");

        self::assertCount(1, $ratings);
        self::assertArrayHasKey($sourceId, $ratings);
        self::assertSame(
            CurrentV1CatalogSourceIds::work("alias-b"),
            $ratings[$sourceId]->workSourceId()
        );
        self::assertSame(0, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::ConvergedRatingConflict
        ));
    }

    public function testAliasCardinalityConflictsFailClosedForEqualOrDifferentReviews(): void
    {
        foreach ([
            ["Synthetic equal review", "Synthetic equal review"],
            ["Synthetic first review", "Synthetic second review"],
        ] as [$first, $second]) {
            $result = $this->map([
                $this->book(
                    "alias-review-a",
                    0,
                    [["date" => "2020-01-02T03:04:05.678Z", "text" => $first]],
                    isbn: "9780306406157",
                    title: "Shared synthetic Work"
                ),
                $this->book(
                    "alias-review-b",
                    0,
                    [["date" => "2020-01-02T03:04:05.678Z", "text" => $second]],
                    isbn: "9780306406157",
                    title: "Shared synthetic Work"
                ),
            ]);

            self::assertSame([], $this->plans(
                $result,
                HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE
            ));
            self::assertSame(2, $this->reasonCount(
                $result,
                CurrentV1AssessmentMappingReason::ConvergedReviewConflict
            ));
        }
    }

    public function testMalformedAndCatBlockedEvidenceIsExplicitAndPrivate(): void
    {
        $invalidRating = $this->book("invalid-rating", 6);
        $invalidReview = $this->book("invalid-review", 0, [[
            "date" => "not-a-date",
            "text" => "private-invalid-review",
        ]]);
        $blocked = $this->book(
            "blocked",
            4,
            [["date" => "2020-01-02T03:04:05.678Z", "text" => "private-blocked"]],
            isbn: "invalid-isbn"
        );
        $result = $this->map([$invalidRating, $invalidReview, $blocked]);

        self::assertSame(1, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::InvalidRating
        ));
        self::assertSame(1, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::InvalidReviewTimestamp
        ));
        self::assertSame(2, $this->reasonCount(
            $result,
            CurrentV1AssessmentMappingReason::UnresolvedWorkIdentity
        ));
        $projection = json_encode(array_map(
            static fn ($finding): array => $finding->toArray(),
            $result->findings()
        ), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("private-invalid-review", $projection);
        self::assertStringNotContainsString("private-blocked", $projection);
    }

    public function testManifestDriftFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("assessment contract does not match");
        $this->map([$this->book("book-a", 5)], str_repeat("b", 64));
    }

    /** @param list<MigrationSourceRecord> $books */
    private function map(
        array $books,
        string $contractDigest = self::DIGEST
    ): MigrationSourceMappingResult {
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $books,
            []
        );
        return (new CurrentV1CatalogMapper(
            assessmentMapper: new CurrentV1AssessmentMapper(
                new CurrentV1ReviewedAssessmentContract($contractDigest)
            )
        ))->map($inspection, $this->target());
    }

    private function target(): MigrationPlanningTarget
    {
        return new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("target-user"),
            new LibraryId("target-library"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
    }

    /** @param list<array{date:string,text:string}> $reviews */
    private function book(
        string $id,
        int $rating,
        array $reviews = [],
        string $reflection = "",
        string $isbn = "",
        ?string $title = null
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => $title ?? "Synthetic assessment Work {$id}",
            "isbn" => $isbn,
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
            "notes" => [],
            "readingRounds" => [],
            "rating" => $rating,
            "reviews" => $reviews,
            "reflection" => $reflection,
        ]);
    }

    /** @return array<string,object> */
    private function plans(MigrationSourceMappingResult $result, string $sourceType): array
    {
        $plans = [];
        foreach ($result->records() as $record) {
            if ($record->sourceType() === $sourceType) {
                $plans[$record->sourceId()] = $record->typedPlan();
            }
        }
        ksort($plans, SORT_STRING);
        return $plans;
    }

    private function reasonCount(
        MigrationSourceMappingResult $result,
        CurrentV1AssessmentMappingReason $reason
    ): int {
        $count = 0;
        foreach ($result->findings() as $finding) {
            if ($finding->reasonCode() === $reason->value) {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }
}
