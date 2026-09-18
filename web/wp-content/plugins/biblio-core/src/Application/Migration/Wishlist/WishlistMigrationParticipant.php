<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationRecordOutcome,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceInspection,
    MigrationSourceRecord,
    PlannedMigrationRecord,
    PreparedMigrationContextParticipant
};
use Biblio\Core\Library\LibraryId;

final readonly class WishlistMigrationParticipant implements
    MigrationParticipant,
    PreparedMigrationContextParticipant
{
    public const SOURCE_TYPE = "wishlist_entry";

    public function __construct(private WishlistMigrationWriter $writer)
    {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = WishlistMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);

        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_work_only_wishlist_entry"]],
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
        $typed = WishlistMigrationPlanGuard::require(
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

    public function assertPreparedContext(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationSourceInspection $inspection,
        array $mappingContracts
    ): void {
        $typed = WishlistMigrationPlanGuard::require($record, $plan);
        if (
            !hash_equals(
                $typed->sourceManifestSha256(),
                $inspection->package()->manifestDigest()
            )
            || $typed->sourceAdapterId() !== $inspection->adapter()->adapterId()
            || $typed->sourceFamily() !== $inspection->adapter()->sourceFamily()
            || $typed->sourceVersion() !== $inspection->profile()->sourceVersion()
            || !in_array($typed->mappingContract(), $mappingContracts, true)
        ) {
            throw new WishlistMigrationFailure(
                WishlistMigrationReason::InvalidPreparedProvenance,
                "Wishlist plan does not match prepared source provenance."
            );
        }
    }

    private function assertTarget(
        WishlistPlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new WishlistMigrationFailure(
                WishlistMigrationReason::OwnerMismatch,
                "Wishlist plan targets another user."
            );
        }
    }
}
