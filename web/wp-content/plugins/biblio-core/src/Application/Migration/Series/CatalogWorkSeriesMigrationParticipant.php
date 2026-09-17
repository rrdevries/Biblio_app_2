<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\{MigrationDisposition, MigrationRecordOutcome, SourceObservation};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant, MigrationPlanningTarget, MigrationSourceRecord, PlannedMigrationRecord};

final readonly class CatalogWorkSeriesMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_work_series";

    public function __construct(private SeriesMigrationWriter $writer) {}
    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(MigrationSourceRecord $record, MigrationPlanningTarget $target): PlannedMigrationRecord
    {
        unset($target);
        /** @var CatalogWorkSeriesPlan $typed */
        $typed = SeriesMigrationPlanGuard::require(self::SOURCE_TYPE, CatalogWorkSeriesPlan::class, $record);
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_work_series_membership"]],
            dependencies: [
                ["source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE, "source_id" => $typed->workSourceId()],
                ["source_type" => CatalogSeriesMigrationParticipant::SOURCE_TYPE, "source_id" => $typed->seriesSourceId()],
            ],
            typedPlan: $typed
        );
    }

    public function apply(MigrationSourceRecord $record, SourceObservation $observation, PlannedMigrationRecord $plan, MigrationPlanningTarget $target): MigrationRecordOutcome
    {
        unset($target);
        /** @var CatalogWorkSeriesPlan $typed */
        $typed = SeriesMigrationPlanGuard::require(self::SOURCE_TYPE, CatalogWorkSeriesPlan::class, $record, $plan, $observation);
        return $this->writer->applyMembership($observation, $typed);
    }
}
