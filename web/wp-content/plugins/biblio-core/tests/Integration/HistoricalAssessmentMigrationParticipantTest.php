<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Assessments\HistoricalAssessmentRecorder;
use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness,PlatformUserDirectory};
use Biblio\Core\Application\Migration\Assessments\{
    AssessmentMigrationFailure,
    AssessmentMigrationReason,
    HistoricalAssessmentMigrationWriter,
    HistoricalRatingMigrationParticipant,
    HistoricalRatingPlan,
    HistoricalWrittenReviewMigrationParticipant,
    HistoricalWrittenReviewPlan
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant,MigrationPlanningTarget,MigrationSourceRecord};
use Biblio\Core\Assessments\{
    AssessmentClock,
    RatingId,
    RatingIdGenerator,
    RatingValue,
    ReviewContent,
    ReviewId,
    ReviewIdGenerator
};
use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbRatingRepository,
    WpdbReadingRoundRepository,
    WpdbReviewRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Reading\{ReadingDate,ReadingPeriod,ReadingRound,ReadingRoundId};
use DateTimeImmutable;
use RuntimeException;

final class AssessmentMigrationTestClock implements AssessmentClock, MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-17T12:00:00.123456+00:00");
    }
}

final class AssessmentMigrationRatingIds implements RatingIdGenerator
{
    private int $next = 0;
    public function __construct(private readonly string $suffix) {}
    public function next(): RatingId
    {
        return new RatingId("migrated-rating-{$this->suffix}-" . ++$this->next);
    }
}

final class AssessmentMigrationReviewIds implements ReviewIdGenerator
{
    private int $next = 0;
    public function __construct(private readonly string $suffix) {}
    public function next(): ReviewId
    {
        return new ReviewId("migrated-review-{$this->suffix}-" . ++$this->next);
    }
}

final readonly class AssessmentMigrationUsers implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool { return true; }
}

final class HistoricalAssessmentMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testPrivateRatingAndReviewUseExactWorkUserAndHistoricalTime(): void
    {
        $fixture = $this->fixture("private");
        $this->mapWork($fixture, "work/source", "work-target");
        $rating = $this->rating($fixture, "rating/source", 4.0);
        $review = $this->review($fixture, "review/source", "Synthetic private review");

        $ratingResult = $this->apply($fixture, $rating, $fixture["ratingParticipant"]);
        $reviewResult = $this->apply($fixture, $review, $fixture["reviewParticipant"]);
        $ratingId = new RatingId($ratingResult["outcome"]->mappings()[0]->targetId());
        $reviewId = new ReviewId($reviewResult["outcome"]->mappings()[0]->targetId());

        $storedRating = $fixture["ratings"]->findForUser($ratingId, $fixture["user"]);
        $storedReview = $fixture["reviews"]->findForUser($reviewId, $fixture["user"]);
        self::assertSame(4.0, $storedRating?->value()->stars());
        self::assertNull($storedRating?->assessedAt());
        self::assertNull($storedRating?->readingRoundId());
        self::assertSame("Synthetic private review", $storedReview?->content()->value());
        self::assertSame(
            "2020-01-02 03:04:05.678000",
            $storedReview?->assessedAt()?->format("Y-m-d H:i:s.u")
        );
        self::assertNull($storedReview?->readingRoundId());
        self::assertSame(0, $this->rows($this->tableNames->contributionPublications()));
        self::assertSame([[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => "work/source",
        ]], $ratingResult["plan"]->dependencies());
        self::assertSame($ratingResult["plan"]->dependencies(), $reviewResult["plan"]->dependencies());
        self::assertArrayNotHasKey("typed_plan", $reviewResult["plan"]->toArray());
    }

    public function testReplayReusesExactTargetsAndChangedPayloadFailsClosed(): void
    {
        $fixture = $this->fixture("replay-a");
        $this->mapWork($fixture, "work/source", "work-target-replay");
        $record = $this->rating($fixture, "rating/source", 5.0);
        $created = $this->apply(
            $fixture,
            $record,
            $fixture["ratingParticipant"]
        )["outcome"]->mappings()[0]->targetId();

        $fixture = $this->laterRun($fixture, "replay-b");
        $replayed = $this->apply(
            $fixture,
            $record,
            $fixture["ratingParticipant"]
        )["outcome"]->mappings()[0];
        self::assertSame($created, $replayed->targetId());
        self::assertSame(MappingDisposition::Reused, $replayed->disposition());
        self::assertSame(1, $this->rows($this->tableNames->ratings()));

        $fixture = $this->laterRun($fixture, "replay-c");
        $this->assertFailure(
            $fixture,
            $this->rating($fixture, "rating/source", 4.0),
            $fixture["ratingParticipant"],
            AssessmentMigrationReason::DivergentReplay
        );
    }

    public function testCardinalityMissingDependencyAndWrongUserFailClosed(): void
    {
        $fixture = $this->fixture("failures");
        $this->mapWork($fixture, "work/source", "work-target-failures");
        $this->apply(
            $fixture,
            $this->rating($fixture, "rating/first", 3.0),
            $fixture["ratingParticipant"]
        );
        $this->assertFailure(
            $fixture,
            $this->rating($fixture, "rating/second", 3.0),
            $fixture["ratingParticipant"],
            AssessmentMigrationReason::AssessmentCardinalityConflict
        );

        $missing = MigrationSourceRecord::typed(
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
            "review/missing-work",
            new HistoricalWrittenReviewPlan(
                $fixture["user"],
                "work/missing",
                ReviewContent::fromString("Synthetic private review"),
                new DateTimeImmutable("2020-01-02T03:04:05.678Z")
            )
        );
        $this->assertFailure(
            $fixture,
            $missing,
            $fixture["reviewParticipant"],
            AssessmentMigrationReason::MissingWorkMapping
        );

        $wrong = MigrationSourceRecord::typed(
            HistoricalRatingMigrationParticipant::SOURCE_TYPE,
            "rating/wrong-user",
            new HistoricalRatingPlan(
                new UserId("another-user"),
                "work/source",
                RatingValue::fromStars(4.0),
                null
            )
        );
        try {
            $fixture["ratingParticipant"]->plan($wrong, $fixture["target"]);
            self::fail("Wrong target User was accepted.");
        } catch (AssessmentMigrationFailure $failure) {
            self::assertSame(AssessmentMigrationReason::OwnerMismatch, $failure->reason());
        }
    }

    public function testOptionalRoundDependencyResolvesExactSameOwnerWorkRound(): void
    {
        $fixture = $this->fixture("round");
        $this->mapWork($fixture, "work/source", "work-target-round");
        $this->mapRound(
            $fixture,
            "round/source",
            "round-target",
            new WorkId("work-target-round")
        );
        $record = MigrationSourceRecord::typed(
            HistoricalRatingMigrationParticipant::SOURCE_TYPE,
            "rating/round",
            new HistoricalRatingPlan(
                $fixture["user"],
                "work/source",
                RatingValue::fromStars(4.0),
                null,
                "round/source"
            )
        );
        $result = $this->apply($fixture, $record, $fixture["ratingParticipant"]);
        $stored = $fixture["ratings"]->findForUser(
            new RatingId($result["outcome"]->mappings()[0]->targetId()),
            $fixture["user"]
        );

        self::assertSame([
            [
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "source_id" => "work/source",
            ],
            [
                "source_type" => ReadingRoundMigrationParticipant::SOURCE_TYPE,
                "source_id" => "round/source",
            ],
        ], $result["plan"]->dependencies());
        self::assertSame("round-target", $stored?->readingRoundId()?->value());
    }

    public function testReplayRejectsTargetAlsoMappedFromAnotherSourceIdentity(): void
    {
        $fixture = $this->fixture("reverse-a");
        $this->mapWork($fixture, "work/source", "work-target-reverse");
        $record = $this->rating($fixture, "rating/source", 5.0);
        $targetId = $this->apply(
            $fixture,
            $record,
            $fixture["ratingParticipant"]
        )["outcome"]->mappings()[0]->targetId();

        $fixture = $this->laterRun($fixture, "reverse-b");
        $foreign = $this->rating($fixture, "rating/foreign-source", 5.0);
        $foreignObservation = $this->observe($fixture, $foreign);
        $fixture["commit"]->commit(
            $fixture["run"],
            $foreignObservation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "rating",
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );

        $fixture = $this->laterRun($fixture, "reverse-c");
        $this->assertFailure(
            $fixture,
            $record,
            $fixture["ratingParticipant"],
            AssessmentMigrationReason::ConflictingAssessmentMapping
        );
    }

    public function testLateFailureRollsBackProductAndMapping(): void
    {
        $fixture = $this->fixture("rollback");
        $this->mapWork($fixture, "work/source", "work-target-rollback");
        $record = $this->review($fixture, "review/rollback", "Synthetic rollback review");
        $plan = $fixture["reviewParticipant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);

        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $observation,
                function () use ($fixture, $record, $observation, $plan): MigrationRecordOutcome {
                    $fixture["reviewParticipant"]->apply(
                        $record,
                        $observation,
                        $plan,
                        $fixture["target"]
                    );
                    throw new RuntimeException("synthetic late failure");
                }
            );
            self::fail("Late assessment failure was hidden.");
        } catch (RuntimeException $failure) {
            self::assertSame("synthetic late failure", $failure->getMessage());
        }
        self::assertSame(0, $this->rows($this->tableNames->reviews()));
        self::assertSame(1, $this->rows($this->tableNames->migrationTargetMappings()));
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("assessment-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $user = new UserId("assessment-migration-user-{$suffix}");
        $clock = new AssessmentMigrationTestClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "assessment-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.43.0",
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $rounds = new WpdbReadingRoundRepository($this->database, $this->tableNames);
        $ratings = new WpdbRatingRepository($this->database, $this->tableNames);
        $reviews = new WpdbReviewRepository($this->database, $this->tableNames);
        $recorder = new HistoricalAssessmentRecorder(
            new AssessmentMigrationUsers(),
            $works,
            $rounds,
            $ratings,
            $reviews,
            new AssessmentMigrationRatingIds($suffix),
            new AssessmentMigrationReviewIds($suffix),
            $clock
        );
        $writer = new HistoricalAssessmentMigrationWriter(
            $ledger,
            $recorder,
            $ratings,
            $reviews
        );
        $fixture = [
            "library" => $library,
            "user" => $user,
            "clock" => $clock,
            "ledger" => $ledger,
            "run" => $run,
            "works" => $works,
            "rounds" => $rounds,
            "ratings" => $ratings,
            "reviews" => $reviews,
            "transactions" => new WpdbTransactionManager($this->database),
            "ratingParticipant" => new HistoricalRatingMigrationParticipant($writer),
            "reviewParticipant" => new HistoricalWrittenReviewMigrationParticipant($writer),
        ];
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(new PersonalMigrationTarget(
            $fixture["user"],
            $fixture["library"],
            new LibraryName("Migration target"),
            new PersonalMigrationTargetReadiness([])
        ));
        $fixture["observe"] = new ObserveSourceRecordService(
            $fixture["ledger"],
            $fixture["clock"]
        );
        $fixture["commit"] = new CommitMigrationRecordService(
            $fixture["ledger"],
            $fixture["transactions"],
            $fixture["clock"]
        );
        return $fixture;
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function laterRun(array $fixture, string $suffix): array
    {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "assessment-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.43.0",
            $fixture["user"],
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture */
    private function mapWork(array $fixture, string $sourceId, string $targetId): void
    {
        $fixture["works"]->add(new Work(new WorkId($targetId), "Mapped Work"));
        $this->map(
            $fixture,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "work",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function mapRound(
        array $fixture,
        string $sourceId,
        string $targetId,
        WorkId $workId
    ): void {
        $fixture["transactions"]->run(function () use (
            $fixture,
            $targetId,
            $workId
        ): void {
            $fixture["rounds"]->addForUser(
                $fixture["user"],
                ReadingRound::historical(
                    new ReadingRoundId($targetId),
                    $fixture["user"],
                    $workId,
                    ReadingPeriod::ended(null, ReadingDate::year(2000)),
                    $fixture["clock"]->now()
                )
            );
        });
        $this->map(
            $fixture,
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "reading_round",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function map(
        array $fixture,
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId
    ): void {
        $record = new MigrationSourceRecord(
            $sourceType,
            $sourceId,
            ["approved_target_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    $targetType,
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function rating(
        array $fixture,
        string $sourceId,
        float $stars
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            HistoricalRatingMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new HistoricalRatingPlan(
                $fixture["user"],
                "work/source",
                RatingValue::fromStars($stars),
                null
            )
        );
    }

    /** @param array<string,mixed> $fixture */
    private function review(
        array $fixture,
        string $sourceId,
        string $content
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new HistoricalWrittenReviewPlan(
                $fixture["user"],
                "work/source",
                ReviewContent::fromString($content),
                new DateTimeImmutable("2020-01-02T03:04:05.678Z")
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(
        array $fixture,
        MigrationSourceRecord $record,
        MigrationParticipant $participant
    ): array {
        $plan = $participant->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $participant->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome");
    }

    /** @param array<string,mixed> $fixture */
    private function assertFailure(
        array $fixture,
        MigrationSourceRecord $record,
        MigrationParticipant $participant,
        AssessmentMigrationReason $reason
    ): void {
        try {
            $this->apply($fixture, $record, $participant);
            self::fail("Assessment migration failure was not raised.");
        } catch (AssessmentMigrationFailure $failure) {
            self::assertSame($reason, $failure->reason());
        }
    }

    /** @param array<string,mixed> $fixture */
    private function observe(array $fixture, MigrationSourceRecord $record): SourceObservation
    {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
