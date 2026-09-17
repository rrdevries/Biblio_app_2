<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationRecordOutcome,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};

final readonly class ReadingTruthMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "reading_truth";

    public function __construct(private ReadingTruthMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = ReadingTruthMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);

        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "record_or_reuse_personal_reading_truth"]],
            dependencies: [[
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "source_id" => $typed->workSourceId(),
            ]],
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        $typed = ReadingTruthMigrationPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        $this->assertTarget($typed, $target);

        return $this->writer->apply($observation, $typed);
    }

    private function assertTarget(
        ReadingTruthPlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new ReadingTruthMigrationFailure(
                ReadingTruthMigrationReason::CrossTargetMapping,
                "Reading Truth plan targets another user."
            );
        }
    }
}
