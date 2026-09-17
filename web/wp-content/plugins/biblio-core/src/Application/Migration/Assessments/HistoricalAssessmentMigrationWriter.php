<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Assessments\HistoricalAssessmentRecorder;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{MappingDisposition,MigrationLedgerRepository,MigrationRecordOutcome,MigrationRun,MigrationTargetMapping,SourceObservation};
use Biblio\Core\Assessments\{Rating,RatingId,RatingNotAvailable,ReviewId,ReviewNotAvailable,WritableRatingRepository,WritableReviewRepository,WrittenReview};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingRoundId;

/** Joins the caller-owned MIG-FND transaction and delegates product writes. */
final readonly class HistoricalAssessmentMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private HistoricalAssessmentRecorder $recorder,
        private WritableRatingRepository $ratings,
        private WritableReviewRepository $reviews
    ) {}

    public function applyRating(
        SourceObservation $observation,
        HistoricalRatingPlan $plan,
        LibraryId $planningLibraryId
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        $this->assertContext($run, $plan->targetUserId()->value(), $planningLibraryId);
        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work",
            AssessmentMigrationReason::MissingWorkMapping
        ));
        $roundId = $this->readingRoundId($run, $plan->readingRoundSourceId());
        $mappedId = $this->mappedTarget(
            $run,
            $observation,
            HistoricalRatingMigrationParticipant::SOURCE_TYPE,
            "rating"
        );
        if ($mappedId !== null) {
            $rating = $this->ratings->findForUser(
                new RatingId($mappedId),
                $plan->targetUserId()
            );
            if ($rating === null || !$this->sameRating($rating, $plan, $workId, $roundId)) {
                throw $this->failure(
                    AssessmentMigrationReason::DivergentReplay,
                    "Mapped Rating no longer matches canonical state."
                );
            }
            $this->assertExclusiveSourceIdentity(
                $run,
                $observation,
                HistoricalRatingMigrationParticipant::SOURCE_TYPE,
                "rating",
                $mappedId
            );
            return MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("rating", $mappedId, MappingDisposition::Reused),
            ]);
        }
        $this->assertRatingCardinality($plan, $workId, $roundId);
        try {
            $rating = $this->recorder->recordRatingForOwner(
                $plan->targetUserId(),
                $workId,
                $roundId,
                $plan->value(),
                $plan->assessedAt()
            );
        } catch (RatingNotAvailable|ValidationException $failure) {
            throw $this->failure(
                AssessmentMigrationReason::InvalidAssessmentContext,
                "Historical Rating target context is invalid.",
                $failure
            );
        }
        return MigrationRecordOutcome::mapped([
            new MigrationTargetMapping(
                "rating",
                $rating->id()->value(),
                MappingDisposition::Created
            ),
        ]);
    }

    public function applyWrittenReview(
        SourceObservation $observation,
        HistoricalWrittenReviewPlan $plan,
        LibraryId $planningLibraryId
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        $this->assertContext($run, $plan->targetUserId()->value(), $planningLibraryId);
        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work",
            AssessmentMigrationReason::MissingWorkMapping
        ));
        $roundId = $this->readingRoundId($run, $plan->readingRoundSourceId());
        $mappedId = $this->mappedTarget(
            $run,
            $observation,
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
            "written_review"
        );
        if ($mappedId !== null) {
            $review = $this->reviews->findForUser(
                new ReviewId($mappedId),
                $plan->targetUserId()
            );
            if ($review === null || !$this->sameReview($review, $plan, $workId, $roundId)) {
                throw $this->failure(
                    AssessmentMigrationReason::DivergentReplay,
                    "Mapped WrittenReview no longer matches canonical state."
                );
            }
            $this->assertExclusiveSourceIdentity(
                $run,
                $observation,
                HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
                "written_review",
                $mappedId
            );
            return MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "written_review",
                    $mappedId,
                    MappingDisposition::Reused
                ),
            ]);
        }
        $this->assertReviewCardinality($plan, $workId, $roundId);
        try {
            $review = $this->recorder->recordReviewForOwner(
                $plan->targetUserId(),
                $workId,
                $roundId,
                $plan->content(),
                $plan->assessedAt()
            );
        } catch (ReviewNotAvailable|ValidationException $failure) {
            throw $this->failure(
                AssessmentMigrationReason::InvalidAssessmentContext,
                "Historical WrittenReview target context is invalid.",
                $failure
            );
        }
        return MigrationRecordOutcome::mapped([
            new MigrationTargetMapping(
                "written_review",
                $review->id()->value(),
                MappingDisposition::Created
            ),
        ]);
    }

    private function assertContext(
        MigrationRun $run,
        string $targetUserId,
        LibraryId $planningLibraryId
    ): void {
        if (
            $run->targetUserId()->value() !== $targetUserId
            || !$run->targetLibraryId()->equals($planningLibraryId)
        ) {
            throw $this->failure(
                AssessmentMigrationReason::OwnerMismatch,
                "Historical assessment does not match the migration target."
            );
        }
    }

    private function readingRoundId(
        MigrationRun $run,
        ?string $sourceId
    ): ?ReadingRoundId {
        return $sourceId === null ? null : new ReadingRoundId(
            $this->requireMappedTarget(
                $run,
                ReadingRoundMigrationParticipant::SOURCE_TYPE,
                $sourceId,
                "reading_round",
                AssessmentMigrationReason::MissingReadingRoundMapping
            )
        );
    }

    private function assertRatingCardinality(
        HistoricalRatingPlan $plan,
        WorkId $workId,
        ?ReadingRoundId $roundId
    ): void {
        $existing = $roundId === null
            ? array_filter(
                $this->ratings->findForUserAndWork($plan->targetUserId(), $workId),
                static fn (Rating $rating): bool => $rating->readingRoundId() === null
            )
            : $this->ratings->findForUserAndRound($plan->targetUserId(), $roundId);
        if ($existing !== []) {
            throw $this->failure(
                AssessmentMigrationReason::AssessmentCardinalityConflict,
                "Historical Rating target cardinality is occupied."
            );
        }
    }

    private function assertReviewCardinality(
        HistoricalWrittenReviewPlan $plan,
        WorkId $workId,
        ?ReadingRoundId $roundId
    ): void {
        $existing = $roundId === null
            ? array_filter(
                $this->reviews->findForUserAndWork($plan->targetUserId(), $workId),
                static fn (WrittenReview $review): bool => $review->readingRoundId() === null
            )
            : $this->reviews->findForUserAndRound($plan->targetUserId(), $roundId);
        if ($existing !== []) {
            throw $this->failure(
                AssessmentMigrationReason::AssessmentCardinalityConflict,
                "Historical WrittenReview target cardinality is occupied."
            );
        }
    }

    private function sameRating(
        Rating $rating,
        HistoricalRatingPlan $plan,
        WorkId $workId,
        ?ReadingRoundId $roundId
    ): bool {
        return $rating->workId()->equals($workId)
            && $rating->hasRound($roundId)
            && $rating->value()->equals($plan->value())
            && $rating->assessedAt() == $plan->assessedAt()
            && $rating->version()->value() === 1;
    }

    private function sameReview(
        WrittenReview $review,
        HistoricalWrittenReviewPlan $plan,
        WorkId $workId,
        ?ReadingRoundId $roundId
    ): bool {
        return $review->workId()->equals($workId)
            && $review->hasRound($roundId)
            && $review->content()->equals($plan->content())
            && $review->assessedAt() == $plan->assessedAt()
            && $review->version()->value() === 1;
    }

    private function mappedTarget(
        MigrationRun $run,
        SourceObservation $observation,
        string $sourceType,
        string $targetType
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $observation->sourceId()) as $trace) {
            if ($trace->targetType() !== $targetType) {
                throw $this->failure(
                    AssessmentMigrationReason::ConflictingAssessmentMapping,
                    "Historical assessment has an unexpected target mapping type."
                );
            }
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    AssessmentMigrationReason::DivergentReplay,
                    "Changed assessment payload cannot reuse a target mapping."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                AssessmentMigrationReason::ConflictingAssessmentMapping,
                "Historical assessment has conflicting target mappings."
            );
        }
        $id = array_key_first($targets);
        return $id === null ? null : (string) $id;
    }

    private function assertExclusiveSourceIdentity(
        MigrationRun $run,
        SourceObservation $observation,
        string $sourceType,
        string $targetType,
        string $targetId
    ): void {
        foreach ($this->ledger->targetSources($run, $targetType, $targetId) as $trace) {
            if (
                $trace->sourceType() !== $sourceType
                || $trace->sourceId() !== $observation->sourceId()
            ) {
                throw $this->failure(
                    AssessmentMigrationReason::ConflictingAssessmentMapping,
                    "Assessment target is mapped from another source identity."
                );
            }
        }
    }

    private function requireRun(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                AssessmentMigrationReason::OwnerMismatch,
                "Migration run is unavailable."
            );
    }

    private function requireMappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType,
        AssessmentMigrationReason $missingReason
    ): string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if ($trace->targetType() !== $targetType) {
                throw $this->failure(
                    AssessmentMigrationReason::WrongMappingTargetType,
                    "Required assessment dependency has an unexpected target type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                $missingReason,
                "Required assessment dependency has no exact committed mapping."
            );
        }
        return (string) array_key_first($targets);
    }

    private function failure(
        AssessmentMigrationReason $reason,
        string $message,
        ?\Throwable $previous = null
    ): AssessmentMigrationFailure {
        return new AssessmentMigrationFailure($reason, $message, $previous);
    }
}
