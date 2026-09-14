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
use Biblio\Core\Library\LibraryId;

final readonly class CatalogItemMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_item";

    public function __construct(private CatalogMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        /** @var CatalogItemPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogItemPlan::class,
            $record
        );
        if (!$typed->targetLibraryId()->equals(new LibraryId($target->libraryId()))) {
            throw new CatalogMigrationFailure(
                CatalogMigrationReason::CrossLibraryTarget,
                "Catalog Item plan targets another Library."
            );
        }
        $this->writer->validateItemPlan($typed);
        $operations = [
            [
                "operation" => "create_or_reuse_item",
                ...($typed->approvedExistingItemId() === null ? [] : [
                    "target_type" => "item",
                    "target_id" => $typed->approvedExistingItemId()->value(),
                ]),
            ],
            ["operation" => "initialize_or_reuse_classification"],
        ];
        if ($typed->localDetails() !== null) {
            $operations[] = ["operation" => "record_item_local_details"];
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            $operations,
            dependencies: [[
                "source_type" => CatalogEditionMigrationParticipant::SOURCE_TYPE,
                "source_id" => $typed->editionSourceId(),
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
        /** @var CatalogItemPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogItemPlan::class,
            $record,
            $plan,
            $observation
        );
        if (!$typed->targetLibraryId()->equals(new LibraryId($target->libraryId()))) {
            throw new CatalogMigrationFailure(
                CatalogMigrationReason::CrossLibraryTarget,
                "Catalog Item apply targets another Library."
            );
        }
        return $this->writer->applyItem($observation, $typed);
    }
}
