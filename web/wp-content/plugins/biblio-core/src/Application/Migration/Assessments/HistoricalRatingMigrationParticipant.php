<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Assessments;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{MigrationDisposition,MigrationRecordOutcome,SourceObservation};
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant,MigrationPlanningTarget,MigrationSourceRecord,PlannedMigrationRecord};
use Biblio\Core\Library\LibraryId;

final readonly class HistoricalRatingMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "historical_rating";

    public function __construct(private HistoricalAssessmentMigrationWriter $writer) {}

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = HistoricalRatingMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);
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
            [["operation" => "create_or_reuse_private_historical_rating"]],
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
        $typed = HistoricalRatingMigrationPlanGuard::require($record, $plan, $observation);
        $this->assertTarget($typed, $target);
        return $this->writer->applyRating(
            $observation,
            $typed,
            new LibraryId($target->libraryId())
        );
    }

    private function assertTarget(
        HistoricalRatingPlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new AssessmentMigrationFailure(
                AssessmentMigrationReason::OwnerMismatch,
                "Historical Rating plan targets another user."
            );
        }
    }
}
