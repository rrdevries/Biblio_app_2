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
use Biblio\Core\Catalog\CanonicalIsbnIdentity;

final readonly class CatalogEditionMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_edition";

    public function __construct(private CatalogMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        /** @var CatalogEditionPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogEditionPlan::class,
            $record
        );
        $operations = [[
            "operation" => "create_or_reuse_edition",
            ...($typed->approvedExistingEditionId() === null ? [] : [
                "target_type" => "edition",
                "target_id" => $typed->approvedExistingEditionId()->value(),
            ]),
        ]];
        if (CanonicalIsbnIdentity::fromMetadata($typed->isbnMetadata()) !== null) {
            $operations[] = ["operation" => "claim_or_reuse_canonical_isbn"];
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            $operations,
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
        unset($target);
        /** @var CatalogEditionPlan $typed */
        $typed = CatalogMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogEditionPlan::class,
            $record,
            $plan,
            $observation
        );
        return $this->writer->applyEdition($observation, $typed);
    }
}
