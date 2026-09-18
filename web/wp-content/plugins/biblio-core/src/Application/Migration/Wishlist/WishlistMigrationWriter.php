<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Wishlist\{
    WishlistEntry,
    WishlistIntentConflict,
    WishlistTargetType,
    WishlistWorkUnavailable
};

/** Joins the caller-owned MIG-FND transaction and never starts or retries it. */
final readonly class WishlistMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private HistoricalWishlistRecorder $recorder
    ) {
    }

    public function apply(
        SourceObservation $observation,
        WishlistPlan $plan,
        LibraryId $planningLibraryId
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        if (!$run->targetUserId()->equals($plan->targetUserId())) {
            throw $this->failure(
                WishlistMigrationReason::OwnerMismatch,
                "Wishlist plan does not match the migration target user."
            );
        }
        if (!$run->targetLibraryId()->equals($planningLibraryId)) {
            throw $this->failure(
                WishlistMigrationReason::OwnerMismatch,
                "Wishlist planning Library does not match the migration run."
            );
        }

        $workId = new WorkId($this->requireMappedWork($run, $plan));
        $mappedId = $this->mappedWishlistEntry($run, $observation);

        try {
            $result = $this->recorder->addWorkOnlyForOwner(
                $plan->targetUserId(),
                $workId,
                $plan->createdAt(),
                $plan->updatedAt()
            );
        } catch (WishlistIntentConflict | WishlistWorkUnavailable | ValidationException $failure) {
            throw $this->failure(
                WishlistMigrationReason::CardinalityConflict,
                "Wishlist target cannot accept the reviewed Work-only intent.",
                $failure
            );
        }

        $entry = $result->entry();
        if (!$this->sameEntry($entry, $plan, $workId)) {
            throw $this->failure(
                WishlistMigrationReason::DivergentReplay,
                "Canonical Wishlist Entry does not match the reviewed plan."
            );
        }
        if ($mappedId !== null && $mappedId !== $entry->id()->value()) {
            throw $this->failure(
                WishlistMigrationReason::ConflictingWishlistMapping,
                "Wishlist source maps to another canonical entry."
            );
        }
        $this->assertCompatibleSourceIdentities(
            $run,
            $observation,
            $entry->id()->value()
        );

        return MigrationRecordOutcome::mapped([
            new MigrationTargetMapping(
                "wishlist_entry",
                $entry->id()->value(),
                $result->wasCreated()
                    ? MappingDisposition::Created
                    : MappingDisposition::Reused
            ),
        ]);
    }

    private function requireRun(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                WishlistMigrationReason::OwnerMismatch,
                "Migration run is unavailable."
            );
    }

    private function requireMappedWork(
        MigrationRun $run,
        WishlistPlan $plan
    ): string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId()
        ) as $trace) {
            if ($trace->targetType() !== "work") {
                throw $this->failure(
                    WishlistMigrationReason::WrongMappingTargetType,
                    "Wishlist Work dependency has an unexpected target type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                WishlistMigrationReason::MissingWorkMapping,
                "Wishlist requires exactly one committed Work mapping."
            );
        }

        return (string) array_key_first($targets);
    }

    private function mappedWishlistEntry(
        MigrationRun $run,
        SourceObservation $observation
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            WishlistMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->targetType() !== "wishlist_entry") {
                throw $this->failure(
                    WishlistMigrationReason::ConflictingWishlistMapping,
                    "Wishlist source has an unexpected target mapping type."
                );
            }
            if (!hash_equals($trace->payloadHash(), $observation->payloadHash())) {
                throw $this->failure(
                    WishlistMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a Wishlist mapping."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                WishlistMigrationReason::ConflictingWishlistMapping,
                "Wishlist source has conflicting target mappings."
            );
        }

        return array_key_first($targets);
    }

    private function assertCompatibleSourceIdentities(
        MigrationRun $run,
        SourceObservation $observation,
        string $targetId
    ): void {
        foreach ($this->ledger->targetSources(
            $run,
            "wishlist_entry",
            $targetId
        ) as $trace) {
            if (
                $trace->sourceType()
                    !== WishlistMigrationParticipant::SOURCE_TYPE
                || !hash_equals(
                    $trace->payloadHash(),
                    $observation->payloadHash()
                )
            ) {
                throw $this->failure(
                    WishlistMigrationReason::ConflictingWishlistMapping,
                    "Canonical Wishlist Entry has an incompatible source intent."
                );
            }
        }
    }

    private function sameEntry(
        WishlistEntry $entry,
        WishlistPlan $plan,
        WorkId $workId
    ): bool {
        return $entry->ownerUserId()->equals($plan->targetUserId())
            && $entry->workId()->equals($workId)
            && $entry->targetType() === WishlistTargetType::WorkOnly
            && $entry->editionId() === null
            && $entry->createdAt() == $plan->createdAt()
            && $entry->updatedAt() == $plan->updatedAt();
    }

    private function failure(
        WishlistMigrationReason $reason,
        string $message,
        ?\Throwable $previous = null
    ): WishlistMigrationFailure {
        return new WishlistMigrationFailure($reason, $message, $previous);
    }
}
