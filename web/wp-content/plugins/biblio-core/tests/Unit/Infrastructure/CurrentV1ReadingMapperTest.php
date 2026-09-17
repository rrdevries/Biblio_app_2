<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingRoundPlan,
    ReadingTruthMigrationParticipant,
    ReadingTruthPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceInspection,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1CatalogMapper,
    CurrentV1ReadingMapper,
    CurrentV1ReadingMappingReason,
    CurrentV1ReadingSourceIds,
    CurrentV1ReviewedReadingContract,
    CurrentV1SourceAdapter
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use Biblio\Core\Reading\{PersonalReadingTruthState,ReadingRoundOutcome};
use PHPUnit\Framework\TestCase;

final class CurrentV1ReadingMapperTest extends TestCase
{
    private const DIGEST = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testReviewedRoundsTruthStatesDatesAndPreservationMapExactly(): void
    {
        $books = [
            $this->book("completed", "finished", "yes", round: $this->round(
                "round-completed",
                ["value" => "2020", "precision" => "year"],
                ["value" => "2020-05", "precision" => "month"]
            )),
            $this->book("stopped", "stopped", "yes", round: $this->round(
                "round-stopped",
                ["value" => "2021-02-03", "precision" => "day"],
                stopped: ["value" => "2021-02-04", "precision" => "day"]
            )),
            $this->book("active", "reading", "yes", round: $this->round(
                "round-active",
                ["value" => "2022-03-04", "precision" => "day"]
            )),
            $this->book("partial", "finished", "yes", registration: [
                "mode" => "partial_finish",
                "partialDate" => ["value" => "2023-06", "precision" => "month"],
            ]),
            $this->book("known", "finished", "yes", registration: [
                "mode" => "unknown_date",
            ]),
            $this->book("unread", "unread", "no"),
            $this->book("unknown", "unknown", "unknown"),
        ];
        $books[1] = $this->withHistory($books[1], [
            ["status" => "paused"],
            ["status" => "stopped"],
        ]);
        $result = $this->map($books);
        $rounds = $this->plans($result->records(), ReadingRoundMigrationParticipant::SOURCE_TYPE);
        $truths = $this->plans($result->records(), ReadingTruthMigrationParticipant::SOURCE_TYPE);

        self::assertCount(3, $rounds);
        self::assertCount(3, $truths);
        self::assertSame(ReadingRoundOutcome::Completed, $rounds["round-completed"]->outcome());
        self::assertSame(2020, $rounds["round-completed"]->period()->startedOn()?->yearValue());
        self::assertNull($rounds["round-completed"]->period()->startedOn()?->monthValue());
        self::assertSame(5, $rounds["round-completed"]->period()->finishedOn()?->monthValue());
        self::assertNull($rounds["round-completed"]->period()->finishedOn()?->dayValue());
        self::assertSame(ReadingRoundOutcome::Stopped, $rounds["round-stopped"]->outcome());
        self::assertSame(
            6,
            $rounds[CurrentV1ReadingSourceIds::registration("partial")]
                ->period()->finishedOn()?->monthValue()
        );
        self::assertArrayNotHasKey("round-active", $rounds);
        self::assertSame(
            PersonalReadingTruthState::ReadKnownDateUnknown,
            $truths[CurrentV1ReadingSourceIds::registration("known")]->state()
        );
        self::assertSame(
            PersonalReadingTruthState::ExplicitNotRead,
            $truths[CurrentV1ReadingSourceIds::status("unread")]->state()
        );
        self::assertSame(
            PersonalReadingTruthState::Unknown,
            $truths[CurrentV1ReadingSourceIds::status("unknown")]->state()
        );
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::ActiveRoundMissingConcreteSource
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::HistoricalPausedAuditEvidence
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::DerivedReadingHistoryEvidence
        ));
    }

    public function testAllReviewedAliasConvergencesAvoidDuplicateOrContradictoryTruth(): void
    {
        $books = [
            $this->book("a1", "finished", "yes", "9780306406157", registration: ["mode" => "unknown_date"]),
            $this->book("a2", "finished", "yes", "9780306406157", registration: ["mode" => "unknown_date"]),
            $this->book("b1", "finished", "yes", "9780140328721", round: $this->round(
                "round-b",
                ["value" => "2020-01-01", "precision" => "day"],
                ["value" => "2020-01-02", "precision" => "day"]
            )),
            $this->book("b2", "finished", "yes", "9780140328721", registration: ["mode" => "unknown_date"]),
            $this->book("c1", "unread", "no", "9780679783268"),
            $this->book("c2", "finished", "yes", "9780679783268", registration: ["mode" => "unknown_date"]),
            $this->book("d1", "unread", "no", "9780451524935"),
            $this->book("d2", "unread", "no", "9780451524935"),
        ];
        $result = $this->map($books);
        $rounds = $this->plans($result->records(), ReadingRoundMigrationParticipant::SOURCE_TYPE);
        $truths = $this->plans($result->records(), ReadingTruthMigrationParticipant::SOURCE_TYPE);

        self::assertCount(1, $rounds);
        self::assertCount(3, $truths);
        self::assertSame(2, count(array_filter(
            $truths,
            static fn (ReadingTruthPlan $plan): bool =>
                $plan->state() === PersonalReadingTruthState::ReadKnownDateUnknown
        )));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::AmbiguousDuplicateOrReread
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::ConflictingBookReadStatus
        ));
        self::assertSame(2, $this->reasonCount(
            $result->findings(),
            CurrentV1ReadingMappingReason::DuplicateReadingTruthEvidence
        ));
    }

    public function testCatQuarantineProducesNoReadingTargetAndMappingIsDeterministic(): void
    {
        $books = [
            $this->book("bad-known", "finished", "yes", "9780306406158", registration: ["mode" => "unknown_date"]),
            $this->book("bad-unknown", "unknown", "unknown", "9780306406158"),
        ];
        $first = $this->map($books);
        $second = $this->map(array_reverse($books));

        self::assertSame([], $this->plans(
            $first->records(),
            ReadingRoundMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame([], $this->plans(
            $first->records(),
            ReadingTruthMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame(2, $this->reasonCount(
            $first->findings(),
            CurrentV1ReadingMappingReason::UnresolvedWorkIdentity
        ));
        self::assertSame(
            array_map(static fn (MigrationSourceRecord $record): string => $record->payloadHash(), $first->records()),
            array_map(static fn (MigrationSourceRecord $record): string => $record->payloadHash(), $second->records())
        );
    }

    public function testUnreferencedStableRoundFailsClosed(): void
    {
        $book = $this->book("book", "unknown", "unknown");
        $orphan = $this->round(
            "round-orphan",
            ["value" => "2020-01-01", "precision" => "day"],
            ["value" => "2020-01-02", "precision" => "day"]
        );
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            [
                $book,
                new MigrationSourceRecord(
                    CurrentV1SourceAdapter::READING_ROUND,
                    "round-orphan",
                    ["book_id" => "book", "record" => $orphan]
                ),
            ],
            []
        );

        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        $this->expectExceptionMessage("not referenced by its parent Book");
        (new CurrentV1CatalogMapper(
            readingMapper: new CurrentV1ReadingMapper(
                new CurrentV1ReviewedReadingContract(self::DIGEST)
            )
        ))->map($inspection, $this->target());
    }

    /** @param list<MigrationSourceRecord> $books */
    private function map(array $books): \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingResult
    {
        $records = $books;
        foreach ($books as $book) {
            foreach ($book->payload()["readingRounds"] as $round) {
                $records[] = new MigrationSourceRecord(
                    CurrentV1SourceAdapter::READING_ROUND,
                    $round["id"],
                    ["book_id" => $book->sourceId(), "record" => $round]
                );
            }
        }
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $records,
            []
        );
        return (new CurrentV1CatalogMapper(
            readingMapper: new CurrentV1ReadingMapper(
                new CurrentV1ReviewedReadingContract(self::DIGEST)
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

    private function book(
        string $id,
        string $status,
        string $marker,
        string $isbn = "",
        ?array $round = null,
        ?array $registration = null
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => "Shared reviewed title",
            "isbn" => $isbn,
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
            "readingRounds" => $round === null ? [] : [$round],
            "readRegistration" => $registration,
            "readStatus" => $status,
            "readMarker" => $marker,
            "readHistory" => [],
        ]);
    }

    private function withHistory(MigrationSourceRecord $book, array $history): MigrationSourceRecord
    {
        $payload = $book->payload();
        $payload["readHistory"] = $history;
        return new MigrationSourceRecord($book->sourceType(), $book->sourceId(), $payload);
    }

    private function round(
        string $id,
        array $started,
        ?array $finished = null,
        ?array $stopped = null
    ): array {
        return [
            "id" => $id,
            "startedAt" => "",
            "startedAtPartial" => $started,
            "finishedAt" => "",
            "finishedAtPartial" => $finished,
            "stoppedAt" => "",
            "stoppedAtPartial" => $stopped,
            "stopReason" => "",
            "pauses" => [],
        ];
    }

    /** @return array<string,ReadingRoundPlan|ReadingTruthPlan> */
    private function plans(array $records, string $sourceType): array
    {
        $plans = [];
        foreach ($records as $record) {
            if ($record->sourceType() === $sourceType) {
                $plans[$record->sourceId()] = $record->typedPlan();
            }
        }
        return $plans;
    }

    private function reasonCount(array $findings, CurrentV1ReadingMappingReason $reason): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if ($finding->reasonCode() === $reason->value) {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }
}
