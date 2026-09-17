<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{MigrationDisposition,MigrationRecordOutcome,SourceObservation};
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant,MigrationPlanningTarget,MigrationSourceRecord,PlannedMigrationRecord};
use Biblio\Core\Assessments\ReviewContent;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class HistoricalWrittenReviewMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "historical_written_review";

    public function __construct(private HistoricalAssessmentMigrationWriter $writer) {}

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = HistoricalWrittenReviewMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);
        $this->assertCanonicalContent($typed);
        $dependencies = [[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => $typed->workSourceId(),
        ]];
        if ($typed->readingRoundSourceId() !== null) {
            $dependencies[] = [
                "source_type" => ReadingRoundMigrationParticipant::SOURCE_TYPE,
                "source_id" => $typed->readingRoundSourceId(),
            ];
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_private_historical_written_review"]],
            dependencies: $dependencies,
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        $typed = HistoricalWrittenReviewMigrationPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        $this->assertTarget($typed, $target);
        $this->assertCanonicalContent($typed);
        return $this->writer->applyWrittenReview(
            $observation,
            $typed,
            new LibraryId($target->libraryId())
        );
    }

    private function assertTarget(
        HistoricalWrittenReviewPlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::OwnerMismatch,
                "Historical WrittenReview plan targets another user."
            );
        }
    }

    private function assertCanonicalContent(HistoricalWrittenReviewPlan $plan): void
    {
        try {
            $canonical = ReviewContent::fromString($plan->content()->value());
        } catch (ValidationException $failure) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::InvalidReviewContent,
                "Historical WrittenReview plan has invalid content.",
                $failure
            );
        }
        if (!$canonical->equals($plan->content())) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::InvalidReviewContent,
                "Historical WrittenReview content is not canonical."
            );
        }
    }
}
