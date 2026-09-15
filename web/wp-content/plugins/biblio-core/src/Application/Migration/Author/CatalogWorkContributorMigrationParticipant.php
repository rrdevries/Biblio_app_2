<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationRecordOutcome,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};

final readonly class CatalogWorkContributorMigrationParticipant implements
    MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_work_contributor";

    public function __construct(private AuthorMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        /** @var CatalogWorkContributorPlan $typed */
        $typed = AuthorMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogWorkContributorPlan::class,
            $record
        );
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [
                ["operation" => "create_or_reuse_author_contributor_credit"],
                ["operation" => "create_or_reuse_work_contributor"],
            ],
            dependencies: [
                [
                    "source_type" => CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                    "source_id" => $typed->authorSourceId(),
                ],
                [
                    "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                    "source_id" => $typed->workSourceId(),
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
        /** @var CatalogWorkContributorPlan $typed */
        $typed = AuthorMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogWorkContributorPlan::class,
            $record,
            $plan,
            $observation
        );
        return $this->writer->applyContributor($observation, $typed);
    }
}
