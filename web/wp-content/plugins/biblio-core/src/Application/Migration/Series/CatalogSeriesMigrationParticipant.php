<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\{MigrationDisposition, MigrationRecordOutcome, SourceObservation};
use Biblio\Core\Application\Migration\Runner\{MigrationParticipant, MigrationPlanningTarget, MigrationSourceRecord, PlannedMigrationRecord};

final readonly class CatalogSeriesMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_series";

    public function __construct(private SeriesMigrationWriter $writer) {}
    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(MigrationSourceRecord $record, MigrationPlanningTarget $target): PlannedMigrationRecord
    {
        unset($target);
        /** @var CatalogSeriesPlan $typed */
        $typed = SeriesMigrationPlanGuard::require(self::SOURCE_TYPE, CatalogSeriesPlan::class, $record);
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_series"]],
            typedPlan: $typed
        );
    }

    public function apply(MigrationSourceRecord $record, SourceObservation $observation, PlannedMigrationRecord $plan, MigrationPlanningTarget $target): MigrationRecordOutcome
    {
        unset($target);
        /** @var CatalogSeriesPlan $typed */
        $typed = SeriesMigrationPlanGuard::require(self::SOURCE_TYPE, CatalogSeriesPlan::class, $record, $plan, $observation);
        return $this->writer->applySeries($observation, $typed);
    }
}
