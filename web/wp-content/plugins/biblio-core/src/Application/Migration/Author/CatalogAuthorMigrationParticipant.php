<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

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

final readonly class CatalogAuthorMigrationParticipant implements
    MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_author";

    public function __construct(private AuthorMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        /** @var CatalogAuthorPlan $typed */
        $typed = AuthorMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogAuthorPlan::class,
            $record
        );
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [[
                "operation" => "create_or_reuse_author",
                ...($typed->approvedExistingAuthorId() === null ? [] : [
                    "target_type" => "author",
                    "target_id" => $typed->approvedExistingAuthorId()->value(),
                ]),
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
        /** @var CatalogAuthorPlan $typed */
        $typed = AuthorMigrationPlanGuard::require(
            self::SOURCE_TYPE,
            CatalogAuthorPlan::class,
            $record,
            $plan,
            $observation
        );
        return $this->writer->applyAuthor($observation, $typed);
    }
}
