<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

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

final readonly class CatalogWorkContainmentMigrationParticipant implements
    MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_work_containment";

    public function __construct(private CatalogWorkContainmentMigrationWriter $writer)
    {
    }

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        $typed = CatalogWorkContainmentPlanGuard::require($record);
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_work_containment"]],
            dependencies: [
                [
                    "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                    "source_id" => $typed->parentWorkSourceId(),
                ],
                [
                    "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                    "source_id" => $typed->childWorkSourceId(),
                ],
            ],
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($target);
        $typed = CatalogWorkContainmentPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        return $this->writer->apply($observation, $typed);
    }
}
