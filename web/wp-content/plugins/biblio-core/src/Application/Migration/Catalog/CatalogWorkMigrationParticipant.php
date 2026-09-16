<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\Runner\MigrationParticipant;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Application\Migration\Runner\PlannedMigrationRecord;
use Biblio\Core\Application\Migration\SourceObservation;

final readonly class CatalogWorkMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_work";

    public function __construct(private CatalogMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        /** @var CatalogWorkPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogWorkPlan::class,
            $record
        );
        $alias = $typed->aliasOfSourceId();
        if ($alias !== null && $alias === $record->sourceId()) {
            throw new CatalogMigrationFailure(
                CatalogMigrationReason::UnsafeWorkMapping,
                "Catalog Work alias cannot reference itself."
            );
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [[
                "operation" => $alias === null
                    ? "create_or_reuse_work"
                    : "reuse_work_source_mapping",
                ...($typed->approvedExistingWorkId() === null ? [] : [
                    "target_type" => "work",
                    "target_id" => $typed->approvedExistingWorkId()->value(),
                ]),
            ]],
            dependencies: $alias === null ? [] : [[
                "source_type" => self::SOURCE_TYPE,
                "source_id" => $alias,
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
        unset($target);
        /** @var CatalogWorkPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogWorkPlan::class,
            $record,
            $plan,
            $observation
        );
        return $this->writer->applyWork($observation, $typed);
    }
}
