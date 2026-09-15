<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Catalog\{
    CatalogItemMigrationParticipant,
    CatalogWorkMigrationParticipant
};
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
use Biblio\Core\Library\LibraryId;

final readonly class ReadingRoundMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "reading_round";

    public function __construct(private ReadingRoundMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = ReadingRoundMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);

        $dependencies = [[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => $typed->workSourceId(),
        ]];
        if ($typed->itemSourceId() !== null) {
            $dependencies[] = [
                "source_type" => CatalogItemMigrationParticipant::SOURCE_TYPE,
                "source_id" => $typed->itemSourceId(),
            ];
        }

        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_reading_round"]],
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
        $typed = ReadingRoundMigrationPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        $this->assertTarget($typed, $target);

        return $this->writer->apply(
            $observation,
            $typed,
            new LibraryId($target->libraryId())
        );
    }

    private function assertTarget(
        ReadingRoundPlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new ReadingRoundMigrationFailure(
                ReadingRoundMigrationReason::CrossTargetMapping,
                "Reading Round plan targets another user."
            );
        }
    }
}
