<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Application\Migration\Catalog\{
    CatalogItemMigrationParticipant,
    CatalogMigrationItemRepository,
    CatalogWorkMigrationParticipant
};
use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Application\Reading\ReadingRoundCreation;
use Biblio\Core\Catalog\{
    EditionRepository,
    ItemId,
    WorkId,
    WorkRepository
};
use Biblio\Core\Reading\{
    PersonalWorkReadingMutationLock,
    ReadingRound,
    ReadingRoundClock,
    ReadingRoundId,
    ReadingRoundProvenance,
    ReadingSource,
    WritableReadingRoundRepository
};
use Biblio\Core\Library\LibraryId;

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class ReadingRoundMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private EditionRepository $editions,
        private CatalogMigrationItemRepository $items,
        private WritableReadingRoundRepository $rounds,
        private ReadingRoundCreation $creation,
        private PersonalWorkReadingMutationLock $lock,
        private ReadingRoundClock $clock
    ) {
    }

    public function apply(
        SourceObservation $observation,
        ReadingRoundPlan $plan,
        LibraryId $planningLibraryId
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        $userId = $plan->targetUserId();
        if (!$run->targetUserId()->equals($userId)) {
            throw $this->failure(
                ReadingRoundMigrationReason::CrossTargetMapping,
                "Reading Round plan does not match the migration target user."
            );
        }
        if (!$run->targetLibraryId()->equals($planningLibraryId)) {
            throw $this->failure(
                ReadingRoundMigrationReason::CrossTargetMapping,
                "Reading Round planning Library does not match the migration run."
            );
        }
        if (!$this->users->isActive($userId)) {
            throw $this->failure(
                ReadingRoundMigrationReason::CrossTargetMapping,
                "Reading Round target user is not active."
            );
        }

        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work",
            ReadingRoundMigrationReason::MissingWorkMapping
        ));
        if ($this->works->find($workId) === null) {
            throw $this->failure(
                ReadingRoundMigrationReason::MissingWorkMapping,
                "Mapped Work dependency does not exist."
            );
        }

        $source = $this->source($run, $plan, $workId);
        $this->lock->acquire($userId, $workId);

        $mappedId = $this->mappedReadingRound($run, $observation);
        if ($mappedId !== null) {
            $round = $this->rounds->findForUser(
                new ReadingRoundId($mappedId),
                $userId
            );
            if (
                $round === null
                || !$this->sameRound($round, $workId, $source, $plan)
            ) {
                throw $this->failure(
                    ReadingRoundMigrationReason::DivergentReplay,
                    "Mapped Reading Round no longer matches canonical state."
                );
            }
            $this->assertExclusiveSourceIdentity($run, $observation, $mappedId);

            return MigrationRecordOutcome::mapped([
                $this->mapping($mappedId, MappingDisposition::Reused),
            ]);
        }

        $round = $this->creation->create(
            $userId,
            fn (ReadingRoundId $id): ReadingRound =>
                ReadingRound::migrationImported(
                    $id,
                    $userId,
                    $workId,
                    $source,
                    $plan->outcome(),
                    $plan->period(),
                    $this->clock->now()
                )
        );

        return MigrationRecordOutcome::mapped([
            $this->mapping($round->id()->value(), MappingDisposition::Created),
        ]);
    }

    private function source(
        MigrationRun $run,
        ReadingRoundPlan $plan,
        WorkId $workId
    ): ?ReadingSource {
        if ($plan->itemSourceId() === null) {
            return null;
        }

        $itemId = new ItemId($this->requireMappedTarget(
            $run,
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            $plan->itemSourceId(),
            "item",
            ReadingRoundMigrationReason::MissingSourceMapping
        ));
        $item = $this->items->findForMigration($itemId);
        $edition = $item === null
            ? null
            : $this->editions->find($item->editionId());
        if (
            $item === null
            || !$item->libraryId()->equals($run->targetLibraryId())
            || $edition === null
            || !$edition->workId()->equals($workId)
        ) {
            throw $this->failure(
                ReadingRoundMigrationReason::MissingSourceMapping,
                "Mapped Item source does not belong to the target Library and Work."
            );
        }

        return ReadingSource::libraryItem($itemId);
    }

    private function mappedReadingRound(
        MigrationRun $run,
        SourceObservation $observation
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->targetType() !== "reading_round") {
                throw $this->failure(
                    ReadingRoundMigrationReason::ConflictingRoundMapping,
                    "Reading Round source has an unexpected target mapping type."
                );
            }
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    ReadingRoundMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a Reading Round mapping."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                ReadingRoundMigrationReason::ConflictingRoundMapping,
                "Reading Round source has conflicting target mappings."
            );
        }

        return array_key_first($targets);
    }

    private function assertExclusiveSourceIdentity(
        MigrationRun $run,
        SourceObservation $observation,
        string $targetId
    ): void {
        foreach ($this->ledger->targetSources(
            $run,
            "reading_round",
            $targetId
        ) as $trace) {
            if (
                $trace->sourceType()
                    !== ReadingRoundMigrationParticipant::SOURCE_TYPE
                || $trace->sourceId() !== $observation->sourceId()
            ) {
                throw $this->failure(
                    ReadingRoundMigrationReason::ConflictingRoundMapping,
                    "Canonical Reading Round is mapped from another source round."
                );
            }
        }
    }

    private function sameRound(
        ReadingRound $round,
        WorkId $workId,
        ?ReadingSource $source,
        ReadingRoundPlan $plan
    ): bool {
        return $round->workId()->equals($workId)
            && ReadingSource::same($round->source(), $source)
            && $round->outcome() === $plan->outcome()
            && $round->period()->equals($plan->period())
            && $round->provenance()
                === ReadingRoundProvenance::MigrationImported;
    }

    private function requireRun(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                ReadingRoundMigrationReason::CrossTargetMapping,
                "Migration run is unavailable."
            );
    }

    private function requireMappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType,
        ReadingRoundMigrationReason $reason
    ): string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if ($trace->targetType() !== $targetType) {
                throw $this->failure(
                    $reason,
                    "Required migration dependency has an unexpected target type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                $reason,
                "Required migration dependency has no exact committed mapping."
            );
        }

        return (string) array_key_first($targets);
    }

    private function mapping(
        string $roundId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping(
            "reading_round",
            $roundId,
            $disposition
        );
    }

    private function failure(
        ReadingRoundMigrationReason $reason,
        string $message
    ): ReadingRoundMigrationFailure {
        return new ReadingRoundMigrationFailure($reason, $message);
    }
}
