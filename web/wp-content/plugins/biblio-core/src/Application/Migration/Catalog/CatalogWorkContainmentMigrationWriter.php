<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Catalog\{
    BibliographicMetadataRepository,
    CatalogRecordAlreadyExists,
    WorkContainment,
    WorkId,
    WorkRepository,
    WritableBibliographicMetadataRepository
};
use Biblio\Core\Exception\ValidationException;
use Throwable;

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class CatalogWorkContainmentMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private WorkRepository $works,
        private BibliographicMetadataRepository $metadata,
        private WritableBibliographicMetadataRepository $writableMetadata
    ) {
    }

    public function apply(
        SourceObservation $observation,
        CatalogWorkContainmentPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $parent = new WorkId($this->requireMappedWork(
            $run,
            $plan->parentWorkSourceId()
        ));
        $child = new WorkId($this->requireMappedWork(
            $run,
            $plan->childWorkSourceId()
        ));
        if ($this->works->find($parent) === null || $this->works->find($child) === null) {
            throw $this->failure(
                CatalogWorkContainmentMigrationReason::MissingTargetReference,
                "Mapped containment Work dependency does not exist."
            );
        }

        $edgeId = self::targetId($parent, $child);
        $mapped = $this->mappedTarget($run, $observation);
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload($run, $observation);
            if ($mapped !== $edgeId || !$this->hasExactRelation($parent, $child, $plan)) {
                throw $this->failure(
                    CatalogWorkContainmentMigrationReason::DivergentReplay,
                    "Mapped Work containment no longer matches canonical state."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping($edgeId, MappingDisposition::Reused),
            ]);
        }

        foreach ($this->metadata->containedWorksForParents([$parent])[$parent->value()] ?? [] as $relation) {
            if ($relation->containedWorkId()->value() === $child->value()) {
                if ($relation->position()->value() !== $plan->position()->value()) {
                    throw $this->failure(
                        CatalogWorkContainmentMigrationReason::RelationConflict,
                        "Existing Work containment has an incompatible position."
                    );
                }
                return MigrationRecordOutcome::mapped([
                    $this->mapping($edgeId, MappingDisposition::Reused),
                ]);
            }
            if ($relation->position()->value() === $plan->position()->value()) {
                throw $this->failure(
                    CatalogWorkContainmentMigrationReason::RelationConflict,
                    "Containment position is occupied by another Work."
                );
            }
        }

        try {
            $this->writableMetadata->addContainment(new WorkContainment(
                $parent,
                $child,
                $plan->position()
            ));
        } catch (CatalogRecordAlreadyExists|ValidationException $exception) {
            throw $this->failure(
                CatalogWorkContainmentMigrationReason::RelationConflict,
                "Work containment conflicted with canonical state.",
                $exception
            );
        }
        return MigrationRecordOutcome::mapped([
            $this->mapping($edgeId, MappingDisposition::Created),
        ]);
    }

    public static function targetId(WorkId $parent, WorkId $child): string
    {
        return "work-containment-" . hash("sha256", implode("\0", [
            "work-containment-v1",
            $parent->value(),
            $child->value(),
        ]));
    }

    private function hasExactRelation(
        WorkId $parent,
        WorkId $child,
        CatalogWorkContainmentPlan $plan
    ): bool {
        foreach ($this->metadata->containedWorksForParents([$parent])[$parent->value()] ?? [] as $relation) {
            if (
                $relation->containedWorkId()->value() === $child->value()
                && $relation->position()->value() === $plan->position()->value()
            ) {
                return true;
            }
        }
        return false;
    }

    private function run(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                CatalogWorkContainmentMigrationReason::MissingTargetReference,
                "Migration run is unavailable."
            );
    }

    private function requireMappedWork(MigrationRun $run, string $sourceId): string
    {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId
        ) as $trace) {
            if ($trace->targetType() !== "work") {
                throw $this->failure(
                    CatalogWorkContainmentMigrationReason::DivergentReplay,
                    "Containment Work dependency has an unexpected mapping type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                count($targets) === 0
                    ? CatalogWorkContainmentMigrationReason::MissingTargetReference
                    : CatalogWorkContainmentMigrationReason::DivergentReplay,
                "Containment Work dependency has no unique committed mapping."
            );
        }
        return (string) array_key_first($targets);
    }

    private function mappedTarget(
        MigrationRun $run,
        SourceObservation $observation
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->targetType() !== "work_containment") {
                throw $this->failure(
                    CatalogWorkContainmentMigrationReason::DivergentReplay,
                    "Containment source identity has an unexpected mapping type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                CatalogWorkContainmentMigrationReason::DivergentReplay,
                "Containment source identity has conflicting target mappings."
            );
        }
        return array_key_first($targets);
    }

    private function assertCommittedMappingMatchesPayload(
        MigrationRun $run,
        SourceObservation $observation
    ): void {
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    CatalogWorkContainmentMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a containment mapping."
                );
            }
        }
    }

    private function mapping(
        string $targetId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping(
            "work_containment",
            $targetId,
            $disposition
        );
    }

    private function failure(
        CatalogWorkContainmentMigrationReason $reason,
        string $message,
        ?Throwable $previous = null
    ): CatalogWorkContainmentMigrationFailure {
        return new CatalogWorkContainmentMigrationFailure(
            $reason,
            $message,
            $previous
        );
    }
}
