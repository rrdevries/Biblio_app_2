<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\PersonalMigrationTargetInvalid;
use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Catalog\HistoricalItemArchiveRecorder;
use Biblio\Core\Application\Assessments\HistoricalAssessmentRecorder;
use Biblio\Core\Application\Migration\BeginMigrationRunService;
use Biblio\Core\Application\Migration\CommitMigrationRecordService;
use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationClock;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationEvidence;
use Biblio\Core\Application\Migration\MigrationMode;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationReconciliationQuery;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationRunIdGenerator;
use Biblio\Core\Application\Migration\MigrationRunLifecycleService;
use Biblio\Core\Application\Migration\MigrationRunStatus;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\MigrationTraceabilityQuery;
use Biblio\Core\Application\Migration\ObserveSourceRecordService;
use Biblio\Core\Application\Migration\QuarantineReason;
use Biblio\Core\Application\Reading\PersonalReadingTruthRecorder;
use Biblio\Core\Application\Wishlist\WishlistRecorder;
use Biblio\Core\Assessments\{AssessmentClock,RatingId,RatingIdGenerator,RatingNotAvailable,RatingValue,ReviewContent,ReviewId,ReviewIdGenerator,ReviewNotAvailable};
use Biblio\Core\Catalog\{Edition,EditionId,Item,ItemArchiveReasonKind,ItemId,ItemStatus,PreservedHistoricalArchiveReason,Work,WorkId};
use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMigrationLedgerRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbRatingRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCollectionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemArchiveRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalReadingTruthRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalWorkReadingMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWishlistRepository;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressPlatformUserDirectory;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\PersonalReadingTruthClock;
use Biblio\Core\Reading\PersonalReadingTruthContradiction;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Wishlist\{WishlistClock,WishlistEntryId,WishlistEntryIdGenerator,WishlistIntentConflict};
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class FoundationMigrationClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-09T12:00:00.123456+00:00");
    }
}

final class FoundationMigrationIds implements MigrationRunIdGenerator
{
    private static int $next = 1;

    public function next(): string
    {
        return "migration-run-test-" . self::$next++;
    }
}

final class FoundationPersonalReadingTruthClock implements PersonalReadingTruthClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-09-09 12:30:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final class FoundationAssessmentClock implements AssessmentClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-09-10 14:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final class FoundationRatingIds implements RatingIdGenerator
{
    private int $next = 1;
    public function next(): RatingId { return new RatingId("migration-rating-" . $this->next++); }
}

final class FoundationReviewIds implements ReviewIdGenerator
{
    private int $next = 1;
    public function next(): ReviewId { return new ReviewId("migration-review-" . $this->next++); }
}

final class FoundationWishlistClock implements WishlistClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-09-10 15:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final class FoundationWishlistIds implements WishlistEntryIdGenerator
{
    private int $next = 1;
    public function next(): WishlistEntryId
    {
        return new WishlistEntryId("migration-wishlist-" . $this->next++);
    }
}

final class MigrationFoundationTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->database->delete($this->database->usermeta, ["user_id" => $userId], ["%d"]);
            $this->database->delete($this->database->users, ["ID" => $userId], ["%d"]);
        }
        parent::tearDown();
    }

    public function testDryRunValidatesExplicitTargetButWritesNoLedgerOrProductData(): void
    {
        [$begin, , , , $target] = $this->foundation("dry-run");

        $run = $begin->begin(
            "biblio-v1",
            "synthetic-snapshot",
            str_repeat("a", 64),
            "synthetic-1",
            "mig-test-1",
            $target->userId(),
            $target->libraryId(),
            MigrationMode::DryRun
        );

        self::assertSame(MigrationMode::DryRun, $run->mode());
        self::assertSame(MigrationRunStatus::Running, $run->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRuns()));
        self::assertSame(0, $this->countRows($this->tableNames->migrationSourceObservations()));

        $this->expectException(PersonalMigrationTargetInvalid::class);
        $begin->begin(
            "biblio-v1",
            "synthetic-snapshot",
            str_repeat("a", 64),
            null,
            "mig-test-1",
            $target->userId(),
            new LibraryId("not-the-designated-library"),
            MigrationMode::DryRun
        );
    }

    public function testApplyRunIsIdempotentTraceableReconciledAndTargetIsolated(): void
    {
        [$begin, $observe, $commit, $lifecycle, $target, $ledger] = $this->foundation("apply-main");
        $run = $this->beginApply($begin, $target, "snapshot-a", "a");
        $same = $this->beginApply($begin, $target, "snapshot-a", "a");
        self::assertSame($run->id(), $same->id());
        self::assertSame(1, $this->countRows($this->tableNames->migrationRuns()));

        $payload = ["title" => "Synthetic", "nested" => ["b" => 2, "a" => 1]];
        $hash = MigrationEvidence::hash($payload);
        $observation = $observe->observe($run, "book", "books/1", $hash, $payload);
        $sameObservation = $observe->observe($run, "book", "books/1", $hash, $payload);
        self::assertSame($observation->id(), $sameObservation->id());

        $outcome = $commit->commit($run, $observation, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::transformed([
                new MigrationTargetMapping("work", "work-1", MappingDisposition::Created),
                new MigrationTargetMapping("edition", "edition-1", MappingDisposition::Created),
                new MigrationTargetMapping("item", "item-1", MappingDisposition::Reused),
            ], "work_edition_item_split")
        );
        self::assertSame(MigrationDisposition::Transformed, $outcome->disposition());
        $duplicateProductWrites = 0;
        try {
            $commit->commit($run, $observation, static function () use (&$duplicateProductWrites): MigrationRecordOutcome {
                $duplicateProductWrites++;
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping("item", "duplicate-item", MappingDisposition::Created),
                ]);
            });
            self::fail("A committed observation was processed twice.");
        } catch (ValidationException) {
            self::assertSame(0, $duplicateProductWrites);
        }

        $trace = new MigrationTraceabilityQuery($ledger);
        self::assertCount(3, $trace->targetsForSource($run, "book", "books/1"));
        self::assertSame("books/1", $trace->sourcesForTarget($run, "item", "item-1")[0]->sourceId());
        $summary = (new MigrationReconciliationQuery($ledger))->forRun($run->id());
        self::assertSame(1, $summary->sourceTotal());
        self::assertSame(1, $summary->dispositions()["transformed"]);
        self::assertSame(3, $summary->mappingEdges());
        self::assertSame(2, $summary->createdTargets());
        self::assertSame(1, $summary->reusedTargets());

        $interrupted = $lifecycle->interrupt($run);
        self::assertSame(MigrationRunStatus::Interrupted, $interrupted->status());
        $resumed = $lifecycle->resume($interrupted);
        self::assertSame(MigrationRunStatus::Running, $resumed->status());
        $completed = $lifecycle->complete($resumed);
        self::assertSame(MigrationRunStatus::Completed, $completed->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRunLocks()));
        $completedRerun = $this->beginApply($begin, $target, "snapshot-a", "a");
        self::assertSame($completed->id(), $completedRerun->id());
        self::assertSame(MigrationRunStatus::Completed, $completedRerun->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRunLocks()));

        $later = $this->beginApply($begin, $target, "snapshot-b", "b");
        $laterObservation = $observe->observe($later, "book", "books/1", $hash, $payload);
        $priorTargets = $ledger->priorTargets($later, $laterObservation);
        self::assertCount(3, $priorTargets);
        foreach ($priorTargets as $mapping) {
            self::assertSame(MappingDisposition::Reused, $mapping->disposition());
        }
        $commit->commit($later, $laterObservation, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::mapped($priorTargets)
        );
        $laterSummary = (new MigrationReconciliationQuery($ledger))->forRun($later->id());
        self::assertSame(1, $laterSummary->sourceTotal());
        self::assertSame(3, $laterSummary->reusedTargets());
        $lifecycle->complete($later);

        $changed = $this->beginApply($begin, $target, "snapshot-c", "c");
        $changedPayload = ["title" => "Synthetic, revised"];
        $changedObservation = $observe->observe(
            $changed,
            "book",
            "books/1",
            MigrationEvidence::hash($changedPayload),
            $changedPayload
        );
        self::assertNotSame($laterObservation->id(), $changedObservation->id());
        self::assertSame([], $ledger->priorTargets($changed, $changedObservation));

        [, , , , $otherTarget] = $this->foundation("apply-other");
        $otherRun = $this->beginApply($begin, $otherTarget, "snapshot-other", "c");
        self::assertSame($otherTarget->userId()->value(), $otherRun->targetUserId()->value());
        self::assertNotSame($changed->targetLibraryId()->value(), $otherRun->targetLibraryId()->value());
        $otherObservation = $observe->observe($otherRun, "book", "books/1", $hash, $payload);
        self::assertSame([], $ledger->priorTargets($otherRun, $otherObservation));
        self::assertSame([], $trace->targetsForSource($otherRun, "book", "books/1"));
        self::assertSame([], $trace->sourcesForTarget($otherRun, "item", "item-1"));
    }

    public function testPreservationQuarantineFailureDropAndRetryRemainDistinct(): void
    {
        [$begin, $observe, $commit, $lifecycle, $target, $ledger] = $this->foundation("outcomes");
        $run = $this->beginApply($begin, $target, "snapshot-outcomes", "d");

        $preserved = $this->observation($observe, $run, "wishlist", "wishlist/1");
        $commit->commit($run, $preserved, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::preserved(
                "wishlist_target_deferred",
                ["intent" => "synthetic"],
                "artifact://snapshot-outcomes/wishlist-1"
            )
        );

        $quarantined = $this->observation($observe, $run, "book", "books/invalid");
        $commit->commit($run, $quarantined, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::quarantined(
                QuarantineReason::InvalidIsbnClaim,
                "The synthetic ISBN claim is invalid.",
                ["field" => "isbn"]
            )
        );

        $failed = $this->observation($observe, $run, "author", "authors/1");
        $commit->commit($run, $failed, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::failed("temporary_dependency_failure", true)
        );
        try {
            $lifecycle->complete($run);
            self::fail("A run with a failed observation completed.");
        } catch (ValidationException $exception) {
            self::assertSame(
                "Migration run cannot complete before exact reconciliation without failed records.",
                $exception->getMessage()
            );
        }
        $commit->commit($run, $failed, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("author", "author-1", MappingDisposition::Created),
            ])
        );
        self::assertSame(
            "temporary_dependency_failure",
            $this->database->get_var(
                "SELECT reason_code FROM " . $this->tableNames->migrationSourceObservations()
                    . " WHERE observation_id='" . $failed->id() . "'"
            )
        );

        $dropped = $this->observation($observe, $run, "preference", "preferences/legacy");
        $commit->commit($run, $dropped, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::intentionallyDropped("explicitly_approved_legacy_preference")
        );

        $summary = (new MigrationReconciliationQuery($ledger))->forRun($run->id());
        self::assertSame(4, $summary->sourceTotal());
        self::assertSame(1, $summary->dispositions()["mapped"]);
        self::assertSame(1, $summary->dispositions()["preserved_deferred"]);
        self::assertSame(1, $summary->dispositions()["quarantined"]);
        self::assertSame(1, $summary->dispositions()["intentionally_dropped"]);
        self::assertSame(0, $summary->dispositions()["failed"]);
        self::assertSame(1, $this->countRows($this->tableNames->migrationPreservations()));
        self::assertSame(1, $this->countRows($this->tableNames->migrationQuarantine()));
        self::assertSame(MigrationRunStatus::Completed, $lifecycle->complete($run)->status());
    }

    public function testProductRollbackCannotLeaveFalseSuccessfulMappingAndRunLockFailsClosed(): void
    {
        [$begin, $observe, $commit, , $target] = $this->foundation("rollback");
        $run = $this->beginApply($begin, $target, "snapshot-rollback", "e");
        $observation = $this->observation($observe, $run, "book", "books/rollback");

        try {
            $commit->commit($run, $observation, function (): MigrationRecordOutcome {
                $this->database->insert(
                    $this->tableNames->works(),
                    ["work_id" => "rolled-back-work", "work_title" => "Synthetic rollback"]
                );
                throw new RuntimeException("synthetic product failure");
            });
            self::fail("Synthetic product failure was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic product failure", $exception->getMessage());
        }

        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM " . $this->tableNames->works()
                . " WHERE work_id='rolled-back-work'"
        ));
        self::assertSame(0, $this->countRows($this->tableNames->migrationTargetMappings()));
        self::assertNull($this->database->get_var(
            "SELECT disposition FROM " . $this->tableNames->migrationSourceObservations()
                . " WHERE observation_id='" . $observation->id() . "'"
        ));

        $this->expectException(ConflictException::class);
        $this->beginApply($begin, $target, "competing-snapshot", "f");
    }

    public function testPersonalReadingTruthWriteAndContradictionUseTheMigFndTransactionAndLedger(): void
    {
        [$begin, $observe, $commit, , $target] = $this->foundation("reading-truth");
        $workId = new WorkId("migration-reading-truth-work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => $workId->value(), "work_title" => "Synthetic migrated truth"]
        ));
        $truths = new WpdbPersonalReadingTruthRepository($this->database, $this->tableNames);
        $recorder = new PersonalReadingTruthRecorder(
            new WordPressPlatformUserDirectory(),
            new WpdbWorkRepository($this->database, $this->tableNames),
            new WpdbReadingRoundRepository($this->database, $this->tableNames),
            $truths,
            new WpdbPersonalWorkReadingMutationLock($this->database, $this->tableNames),
            new FoundationPersonalReadingTruthClock()
        );
        $run = $this->beginApply($begin, $target, "snapshot-reading-truth", "a");
        $readObservation = $this->observation(
            $observe,
            $run,
            "reading_truth",
            "reading-truth/known-read"
        );

        $readOutcome = $commit->commit(
            $run,
            $readObservation,
            function () use ($recorder, $target, $workId): MigrationRecordOutcome {
                $recorder->recordForOwner(
                    $target->userId(),
                    $workId,
                    PersonalReadingTruthState::ReadKnownDateUnknown
                );
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "personal_reading_truth",
                        $workId->value(),
                        MappingDisposition::Created
                    ),
                ]);
            }
        );
        self::assertSame(MigrationDisposition::Mapped, $readOutcome->disposition());
        self::assertSame(
            PersonalReadingTruthState::ReadKnownDateUnknown,
            $truths->findForUserAndWork($target->userId(), $workId)?->state()
        );
        $retryWrites = 0;
        try {
            $commit->commit(
                $run,
                $readObservation,
                static function () use (&$retryWrites): MigrationRecordOutcome {
                    ++$retryWrites;
                    return MigrationRecordOutcome::failed("unexpected_retry", false);
                }
            );
            self::fail("A committed Reading Truth observation was processed twice.");
        } catch (ValidationException) {
            self::assertSame(0, $retryWrites);
        }

        $rollbackWork = new WorkId("migration-reading-truth-rollback-work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => $rollbackWork->value(), "work_title" => "Synthetic rollback truth"]
        ));
        $rollbackObservation = $this->observation(
            $observe,
            $run,
            "reading_truth",
            "reading-truth/rollback"
        );
        try {
            $commit->commit(
                $run,
                $rollbackObservation,
                function () use ($recorder, $target, $rollbackWork): MigrationRecordOutcome {
                    $recorder->recordForOwner(
                        $target->userId(),
                        $rollbackWork,
                        PersonalReadingTruthState::Unknown
                    );
                    throw new RuntimeException("synthetic Reading Truth rollback");
                }
            );
            self::fail("Synthetic Reading Truth rollback was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic Reading Truth rollback", $exception->getMessage());
        }
        self::assertNull($truths->findForUserAndWork($target->userId(), $rollbackWork));
        self::assertSame(1, $this->countRows($this->tableNames->migrationTargetMappings()));

        $this->insertCompletedRound(
            "migration-truth-completed",
            $target->userId(),
            $workId
        );
        $conflictObservation = $this->observation(
            $observe,
            $run,
            "reading_truth",
            "reading-truth/contradiction"
        );
        $conflictOutcome = $commit->commit(
            $run,
            $conflictObservation,
            function () use ($recorder, $target, $workId): MigrationRecordOutcome {
                try {
                    $recorder->recordForOwner(
                        $target->userId(),
                        $workId,
                        PersonalReadingTruthState::ExplicitNotRead
                    );
                } catch (PersonalReadingTruthContradiction) {
                    return MigrationRecordOutcome::quarantined(
                        QuarantineReason::ReadingTruthConflict,
                        "Synthetic explicit-not-read truth contradicts a completed round.",
                        ["work_id" => $workId->value()]
                    );
                }
                self::fail("Migration contradiction was not quarantined.");
            }
        );

        self::assertSame(MigrationDisposition::Quarantined, $conflictOutcome->disposition());
        self::assertSame(1, $this->countRows($this->tableNames->migrationQuarantine()));
        self::assertSame(1, $this->countRows($this->tableNames->migrationTargetMappings()));
        self::assertSame(
            PersonalReadingTruthState::ReadKnownDateUnknown,
            $truths->findForUserAndWork($target->userId(), $workId)?->state()
        );
    }

    public function testWishlistUsesMigFndWithStableRefinementRetryRollbackAndQuarantine(): void
    {
        [$begin, $observe, $commit, , $target, $ledger] = $this->foundation(
            "wishlist-foundation"
        );
        $run = $this->beginApply($begin, $target, "snapshot-wishlist", "f");
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $work = new Work(new WorkId("migration-wishlist-work"), "Synthetic Wishlist Work");
        $rollbackWork = new Work(new WorkId("migration-wishlist-rollback"), "Rollback Work");
        $edition = new Edition(
            new EditionId("migration-wishlist-edition"),
            $work->id(),
            "Synthetic Wishlist Edition"
        );
        $works->add($work);
        $works->add($rollbackWork);
        $editions->add($edition);
        $wishlist = new WpdbWishlistRepository($this->database, $this->tableNames);
        $recorder = new WishlistRecorder(
            new WordPressPlatformUserDirectory(),
            $works,
            $editions,
            $wishlist,
            new FoundationWishlistIds(),
            new FoundationWishlistClock()
        );

        $workObservation = $this->observation(
            $observe,
            $run,
            "wishlist",
            "wishlist/work-only"
        );
        $workOutcome = $commit->commit(
            $run,
            $workObservation,
            function () use ($recorder, $target, $work): MigrationRecordOutcome {
                $result = $recorder->addWorkOnlyForOwner($target->userId(), $work->id());
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "wishlist_entry",
                        $result->entry()->id()->value(),
                        MappingDisposition::Created
                    ),
                ]);
            }
        );
        $entryId = $workOutcome->mappings()[0]->targetId();

        $duplicateObservation = $this->observation(
            $observe,
            $run,
            "wishlist",
            "wishlist/exact-duplicate"
        );
        $duplicateOutcome = $commit->commit(
            $run,
            $duplicateObservation,
            function () use ($recorder, $target, $work): MigrationRecordOutcome {
                $result = $recorder->addWorkOnlyForOwner($target->userId(), $work->id());
                self::assertFalse($result->wasCreated());
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "wishlist_entry",
                        $result->entry()->id()->value(),
                        MappingDisposition::Reused
                    ),
                ]);
            }
        );
        self::assertSame($entryId, $duplicateOutcome->mappings()[0]->targetId());

        $refinementObservation = $this->observation(
            $observe,
            $run,
            "wishlist",
            "wishlist/refinement"
        );
        $refinementOutcome = $commit->commit(
            $run,
            $refinementObservation,
            function () use ($recorder, $target, $work, $edition): MigrationRecordOutcome {
                $result = $recorder->refineWorkOnlyForOwner(
                    $target->userId(),
                    $work->id(),
                    $edition->id()
                );
                return MigrationRecordOutcome::transformed([
                    new MigrationTargetMapping(
                        "wishlist_entry",
                        $result->entry()->id()->value(),
                        MappingDisposition::Reused
                    ),
                ], "work_only_refined_to_edition");
            }
        );
        self::assertSame($entryId, $refinementOutcome->mappings()[0]->targetId());
        self::assertSame(3, count((new MigrationTraceabilityQuery($ledger))
            ->sourcesForTarget($run, "wishlist_entry", $entryId)));

        $retryWrites = 0;
        try {
            $commit->commit(
                $run,
                $workObservation,
                static function () use (&$retryWrites): MigrationRecordOutcome {
                    ++$retryWrites;
                    return MigrationRecordOutcome::failed("unexpected_retry", false);
                }
            );
            self::fail("A committed Wishlist observation was processed twice.");
        } catch (ValidationException) {
            self::assertSame(0, $retryWrites);
        }

        $rollbackObservation = $this->observation(
            $observe,
            $run,
            "wishlist",
            "wishlist/rollback"
        );
        try {
            $commit->commit(
                $run,
                $rollbackObservation,
                function () use ($recorder, $target, $rollbackWork): MigrationRecordOutcome {
                    $recorder->addWorkOnlyForOwner(
                        $target->userId(),
                        $rollbackWork->id()
                    );
                    throw new RuntimeException("synthetic Wishlist rollback");
                }
            );
            self::fail("Synthetic Wishlist rollback was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic Wishlist rollback", $exception->getMessage());
        }
        self::assertSame(0, (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}` WHERE work_id=%s",
            $rollbackWork->id()->value()
        )));

        $conflictObservation = $this->observation(
            $observe,
            $run,
            "wishlist",
            "wishlist/edition-to-work-conflict"
        );
        $conflict = $commit->commit(
            $run,
            $conflictObservation,
            function () use ($recorder, $target, $work): MigrationRecordOutcome {
                try {
                    $recorder->addWorkOnlyForOwner($target->userId(), $work->id());
                } catch (WishlistIntentConflict) {
                    return MigrationRecordOutcome::quarantined(
                        QuarantineReason::UnsupportedTargetRepresentation,
                        "Synthetic Edition-specific intent cannot be collapsed to Work-only."
                    );
                }
                self::fail("Wishlist conflict was not quarantined.");
            }
        );
        self::assertSame(MigrationDisposition::Quarantined, $conflict->disposition());
        self::assertSame(1, $this->countRows($this->tableNames->wishlistEntries()));
        self::assertSame(3, $this->countRows($this->tableNames->migrationTargetMappings()));

        [$otherBegin, $otherObserve, $otherCommit, , $otherTarget] =
            $this->foundation("wishlist-foundation-other");
        $otherRun = $this->beginApply(
            $otherBegin,
            $otherTarget,
            "snapshot-wishlist-other",
            "e"
        );
        $otherObservation = $this->observation(
            $otherObserve,
            $otherRun,
            "wishlist",
            "wishlist/work-only"
        );
        $otherOutcome = $otherCommit->commit(
            $otherRun,
            $otherObservation,
            function () use ($recorder, $otherTarget, $work, $edition): MigrationRecordOutcome {
                $result = $recorder->addEditionForOwner(
                    $otherTarget->userId(),
                    $work->id(),
                    $edition->id()
                );
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "wishlist_entry",
                        $result->entry()->id()->value(),
                        MappingDisposition::Created
                    ),
                ]);
            }
        );
        self::assertNotSame($entryId, $otherOutcome->mappings()[0]->targetId());
        self::assertSame(2, $this->countRows($this->tableNames->wishlistEntries()));
    }

    public function testHistoricalArchiveWriteUsesMigFndTransactionRetryAndQuarantine(): void
    {
        [$begin, $observe, $commit, , $target, $ledger] = $this->foundation(
            "historical-archive"
        );
        $run = $this->beginApply(
            $begin,
            $target,
            "snapshot-historical-archive",
            "a"
        );
        $work = new Work(
            new WorkId("migration-archive-work"),
            "Synthetic archive Work"
        );
        $edition = new Edition(
            new EditionId("migration-archive-edition"),
            $work->id(),
            "Synthetic archive Edition"
        );
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $archives = new WpdbItemArchiveRepository(
            $this->database,
            $this->tableNames
        );
        $works->add($work);
        $editions->add($edition);
        $item = Item::active(
            new ItemId("migration-archive-item"),
            $target->libraryId(),
            $edition->id()
        );
        $rollbackItem = Item::active(
            new ItemId("migration-archive-rollback-item"),
            $target->libraryId(),
            $edition->id()
        );
        $items->add($item);
        $items->add($rollbackItem);
        $recorder = new HistoricalItemArchiveRecorder(
            $archives,
            new WpdbCollectionRepository($this->database, $this->tableNames)
        );
        $observation = $this->observation(
            $observe,
            $run,
            "item_archive",
            "archive/item-a"
        );
        $archivedAt = new DateTimeImmutable(
            "2026-09-08 09:00:00.123456",
            new DateTimeZone("UTC")
        );

        $outcome = $commit->commit(
            $run,
            $observation,
            function () use (
                $recorder,
                $run,
                $item,
                $archivedAt
            ): MigrationRecordOutcome {
                $recorder->recordForLibrary(
                    $run->targetLibraryId(),
                    $item->id(),
                    new PreservedHistoricalArchiveReason(
                        "historical reason A",
                        "source-code-a"
                    ),
                    $archivedAt,
                    $item->version()
                );

                return MigrationRecordOutcome::transformed([
                    new MigrationTargetMapping(
                        "item",
                        $item->id()->value(),
                        MappingDisposition::Created
                    ),
                ], "historical_archive_reason_preserved");
            }
        );
        self::assertSame(MigrationDisposition::Transformed, $outcome->disposition());
        $period = $archives->periodsForItems(
            $target->libraryId(),
            [$item->id()]
        )[$item->id()->value()][0];
        self::assertSame(
            ItemArchiveReasonKind::PreservedHistorical,
            $period->reason()->kind()
        );
        self::assertSame(
            ItemStatus::Archived,
            $items->findInLibrary($item->id(), $target->libraryId())?->status()
        );
        self::assertCount(
            1,
            (new MigrationTraceabilityQuery($ledger))->targetsForSource(
                $run,
                "item_archive",
                "archive/item-a"
            )
        );

        $retryWrites = 0;
        try {
            $commit->commit(
                $run,
                $observation,
                static function () use (&$retryWrites): MigrationRecordOutcome {
                    ++$retryWrites;
                    return MigrationRecordOutcome::failed("unexpected_retry", false);
                }
            );
            self::fail("A committed historical archive observation was retried.");
        } catch (ValidationException) {
            self::assertSame(0, $retryWrites);
        }

        $rollbackObservation = $this->observation(
            $observe,
            $run,
            "item_archive",
            "archive/rollback"
        );
        try {
            $commit->commit(
                $run,
                $rollbackObservation,
                function () use (
                    $recorder,
                    $run,
                    $rollbackItem,
                    $archivedAt
                ): MigrationRecordOutcome {
                    $recorder->recordForLibrary(
                        $run->targetLibraryId(),
                        $rollbackItem->id(),
                        new PreservedHistoricalArchiveReason(
                            "historical reason rollback"
                        ),
                        $archivedAt,
                        $rollbackItem->version()
                    );
                    throw new RuntimeException("synthetic archive rollback");
                }
            );
            self::fail("Synthetic archive rollback was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic archive rollback", $exception->getMessage());
        }
        self::assertSame(
            ItemStatus::Active,
            $items->findInLibrary(
                $rollbackItem->id(),
                $target->libraryId()
            )?->status()
        );
        self::assertSame(
            [],
            $archives->periodsForItems(
                $target->libraryId(),
                [$rollbackItem->id()]
            )[$rollbackItem->id()->value()]
        );

        $malformed = $this->observation(
            $observe,
            $run,
            "item_archive",
            "archive/malformed"
        );
        $malformedOutcome = $commit->commit(
            $run,
            $malformed,
            static function (): MigrationRecordOutcome {
                try {
                    new PreservedHistoricalArchiveReason("   ");
                } catch (ValidationException) {
                    return MigrationRecordOutcome::quarantined(
                        QuarantineReason::UnsupportedTargetRepresentation,
                        "Synthetic historical archive reason is not representable."
                    );
                }

                self::fail("Malformed archive reason was not quarantined.");
            }
        );
        self::assertSame(
            MigrationDisposition::Quarantined,
            $malformedOutcome->disposition()
        );
    }

    public function testHistoricalAssessmentsUseMigFndWithoutPublicationOrInventedTime(): void
    {
        [$begin, $observe, $commit, , $target, $ledger] = $this->foundation(
            "historical-assessments"
        );
        $run = $this->beginApply(
            $begin,
            $target,
            "snapshot-historical-assessments",
            "b"
        );
        $workId = new WorkId("migration-assessment-work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            [
                "work_id" => $workId->value(),
                "work_title" => "Synthetic assessment Work",
            ]
        ), $this->database->last_error);
        $ratings = new WpdbRatingRepository($this->database, $this->tableNames);
        $reviews = new WpdbReviewRepository($this->database, $this->tableNames);
        $recorder = new HistoricalAssessmentRecorder(
            new WordPressPlatformUserDirectory(),
            new WpdbWorkRepository($this->database, $this->tableNames),
            new WpdbReadingRoundRepository($this->database, $this->tableNames),
            $ratings,
            $reviews,
            new FoundationRatingIds(),
            new FoundationReviewIds(),
            new FoundationAssessmentClock()
        );
        $otherWorkId = new WorkId("migration-assessment-other-work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            [
                "work_id" => $otherWorkId->value(),
                "work_title" => "Synthetic other assessment Work",
            ]
        ), $this->database->last_error);
        $this->insertCompletedRound(
            "migration-assessment-wrong-owner-round",
            new UserId("999999"),
            $workId
        );
        $this->insertCompletedRound(
            "migration-assessment-wrong-work-round",
            $target->userId(),
            $otherWorkId
        );
        $assessmentTransactions = new WpdbTransactionManager($this->database);
        try {
            $assessmentTransactions->run(static fn () =>
                $recorder->recordRatingForOwner(
                    $target->userId(),
                    $workId,
                    new ReadingRoundId("migration-assessment-wrong-owner-round"),
                    RatingValue::fromStars(4.0),
                    null
                )
            );
            self::fail("A ReadingRound owned by another user was accepted.");
        } catch (RatingNotAvailable) {
            self::assertSame(0, $this->countRows($this->tableNames->ratings()));
        }
        try {
            $assessmentTransactions->run(static fn () =>
                $recorder->recordReviewForOwner(
                    $target->userId(),
                    $workId,
                    new ReadingRoundId("migration-assessment-wrong-work-round"),
                    ReviewContent::fromString("Synthetic rejected review"),
                    null
                )
            );
            self::fail("A ReadingRound for another Work was accepted.");
        } catch (ReviewNotAvailable) {
            self::assertSame(0, $this->countRows($this->tableNames->reviews()));
        }
        $ratingObservation = $this->observation(
            $observe,
            $run,
            "rating",
            "assessment/rating-unknown-time"
        );

        $ratingOutcome = $commit->commit(
            $run,
            $ratingObservation,
            function () use ($recorder, $target, $workId): MigrationRecordOutcome {
                $rating = $recorder->recordRatingForOwner(
                    $target->userId(),
                    $workId,
                    null,
                    RatingValue::fromStars(4.5),
                    null
                );
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "rating",
                        $rating->id()->value(),
                        MappingDisposition::Created
                    ),
                ]);
            }
        );
        $ratingMapping = $ratingOutcome->mappings()[0];
        $rating = $ratings->findForUser(
            new RatingId($ratingMapping->targetId()),
            $target->userId()
        );
        self::assertNotNull($rating);
        self::assertNull($rating->assessedAt());
        self::assertSame(
            "2026-09-10 14:00:00.123456",
            $rating->createdAt()->format("Y-m-d H:i:s.u")
        );

        $known = new DateTimeImmutable(
            "2014-03-02 11:12:13.654321",
            new DateTimeZone("UTC")
        );
        $reviewObservation = $this->observation(
            $observe,
            $run,
            "written_review",
            "assessment/review-known-time"
        );
        $reviewOutcome = $commit->commit(
            $run,
            $reviewObservation,
            function () use ($recorder, $target, $workId, $known): MigrationRecordOutcome {
                $review = $recorder->recordReviewForOwner(
                    $target->userId(),
                    $workId,
                    null,
                    ReviewContent::fromString("Synthetic historical review"),
                    $known
                );
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "written_review",
                        $review->id()->value(),
                        MappingDisposition::Created
                    ),
                ]);
            }
        );
        $review = $reviews->findForUser(
            new ReviewId($reviewOutcome->mappings()[0]->targetId()),
            $target->userId()
        );
        self::assertEquals($known, $review?->assessedAt());
        self::assertNotSame(
            $review?->createdAt()->format("Y-m-d H:i:s.u"),
            $review?->assessedAt()?->format("Y-m-d H:i:s.u")
        );
        self::assertSame(
            0,
            $this->countRows($this->tableNames->contributionPublications())
        );
        self::assertCount(1, (new MigrationTraceabilityQuery($ledger))->targetsForSource(
            $run,
            "rating",
            "assessment/rating-unknown-time"
        ));

        $retryWrites = 0;
        try {
            $commit->commit(
                $run,
                $ratingObservation,
                static function () use (&$retryWrites): MigrationRecordOutcome {
                    ++$retryWrites;
                    return MigrationRecordOutcome::failed("unexpected_retry", false);
                }
            );
            self::fail("A committed assessment observation was retried.");
        } catch (ValidationException) {
            self::assertSame(0, $retryWrites);
        }

        $rollbackObservation = $this->observation(
            $observe,
            $run,
            "rating",
            "assessment/rollback"
        );
        $rollbackWorkId = new WorkId("migration-assessment-rollback-work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            [
                "work_id" => $rollbackWorkId->value(),
                "work_title" => "Synthetic rollback assessment Work",
            ]
        ), $this->database->last_error);
        try {
            $commit->commit(
                $run,
                $rollbackObservation,
                function () use ($recorder, $target, $rollbackWorkId): MigrationRecordOutcome {
                    $recorder->recordRatingForOwner(
                        $target->userId(),
                        $rollbackWorkId,
                        null,
                        RatingValue::fromStars(3.0),
                        null
                    );
                    throw new RuntimeException("synthetic assessment rollback");
                }
            );
            self::fail("Synthetic assessment rollback was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic assessment rollback", $exception->getMessage());
        }
        self::assertSame(1, $this->countRows($this->tableNames->ratings()));
        self::assertSame(2, $this->countRows($this->tableNames->migrationTargetMappings()));

        $malformed = $this->observation(
            $observe,
            $run,
            "rating",
            "assessment/malformed"
        );
        $malformedOutcome = $commit->commit(
            $run,
            $malformed,
            static function (): MigrationRecordOutcome {
                try {
                    RatingValue::fromStars(4.2);
                } catch (ValidationException) {
                    return MigrationRecordOutcome::quarantined(
                        QuarantineReason::UnsupportedTargetRepresentation,
                        "Synthetic historical rating is not representable."
                    );
                }
                self::fail("Malformed assessment was not quarantined.");
            }
        );
        self::assertSame(MigrationDisposition::Quarantined, $malformedOutcome->disposition());
    }

    private function beginApply(
        BeginMigrationRunService $begin,
        PersonalMigrationTarget $target,
        string $snapshot,
        string $hashSeed
    ): MigrationRun
    {
        return $begin->begin(
            "biblio-v1",
            $snapshot,
            str_repeat($hashSeed, 64),
            "synthetic-1",
            "mig-test-1",
            $target->userId(),
            $target->libraryId(),
            MigrationMode::Apply
        );
    }

    private function observation(
        ObserveSourceRecordService $observe,
        MigrationRun $run,
        string $type,
        string $id
    ): \Biblio\Core\Application\Migration\SourceObservation {
        $payload = ["source_id" => $id];
        return $observe->observe($run, $type, $id, MigrationEvidence::hash($payload), $payload);
    }

    /** @return array{BeginMigrationRunService,ObserveSourceRecordService,CommitMigrationRecordService,MigrationRunLifecycleService,PersonalMigrationTarget,WpdbMigrationLedgerRepository} */
    private function foundation(string $login): array
    {
        $userId = wp_create_user($login, "synthetic-test-password", $login . "@example.invalid");
        if (!is_int($userId)) {
            throw new RuntimeException("Could not create synthetic migration test user.");
        }
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(new UserId((string) $userId));
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new FoundationMigrationClock();
        $begin = new BeginMigrationRunService(
            $application->personalMigrationTargets(),
            $ledger,
            $transactions,
            $clock,
            new FoundationMigrationIds()
        );

        return [
            $begin,
            new ObserveSourceRecordService($ledger, $clock),
            new CommitMigrationRecordService($ledger, $transactions, $clock),
            new MigrationRunLifecycleService($ledger, $transactions, $clock),
            $target,
            $ledger,
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM " . $table);
    }

    private function insertCompletedRound(
        string $roundId,
        UserId $userId,
        WorkId $workId
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->readingRounds(),
            [
                "reading_round_id" => $roundId,
                "user_id" => $userId->value(),
                "work_id" => $workId->value(),
                "item_id" => null,
                "external_loan_id" => null,
                "started_at" => null,
                "round_outcome" => "completed",
                "provenance" => "historical_manual",
                "reading_started_year" => null,
                "reading_started_month" => null,
                "reading_started_day" => null,
                "reading_finished_year" => 2025,
                "reading_finished_month" => null,
                "reading_finished_day" => null,
                "created_at" => "2026-09-09 12:31:00.000000",
                "updated_at" => "2026-09-09 12:31:00.000000",
                "ended_at" => "2026-09-09 12:31:00.000000",
                "round_version" => 1,
            ]
        ), $this->database->last_error);
    }
}
