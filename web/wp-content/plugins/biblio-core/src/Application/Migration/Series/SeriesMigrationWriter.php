<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Series;

use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan
};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Catalog\{
    CatalogRecordAlreadyExists,
    Series,
    SeriesId,
    WorkId,
    WorkRepository,
    WorkSeriesMembership,
    WritableSeriesRepository
};
use Throwable;

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class SeriesMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private WritableSeriesRepository $series,
        private WorkRepository $works,
        private ?SeriesPreservationPromotionPolicy $promotionPolicy = null
    ) {}

    public function applySeries(SourceObservation $observation, CatalogSeriesPlan $plan): MigrationRecordOutcome
    {
        $run = $this->run($observation);
        $mapped = $this->mappedTarget(
            $run,
            CatalogSeriesMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "series"
        );
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogSeriesMigrationParticipant::SOURCE_TYPE,
                $observation
            );
            $stored = $this->series->find(new SeriesId($mapped));
            if ($stored === null || $stored->displayName() !== $plan->displayName()) {
                throw $this->failure(
                    SeriesMigrationReason::DivergentReplay,
                    "Mapped Series no longer matches the exact planned display name."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping("series", $mapped, MappingDisposition::Reused),
            ]);
        }

        $seriesId = self::targetSeriesId($observation->sourceId());
        if ($this->series->find($seriesId) !== null) {
            throw $this->failure(
                SeriesMigrationReason::TargetCollision,
                "Deterministic Series target identity is already occupied without a source mapping."
            );
        }
        $this->series->save(new Series($seriesId, $plan->displayName()));
        return MigrationRecordOutcome::mapped([
            $this->mapping("series", $seriesId->value(), MappingDisposition::Created),
        ]);
    }

    public function applyMembership(SourceObservation $observation, CatalogWorkSeriesPlan $plan): MigrationRecordOutcome
    {
        $run = $this->run($observation);
        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        ));
        $seriesId = new SeriesId($this->requireMappedTarget(
            $run,
            CatalogSeriesMigrationParticipant::SOURCE_TYPE,
            $plan->seriesSourceId(),
            "series"
        ));
        if ($this->works->find($workId) === null || $this->series->find($seriesId) === null) {
            throw $this->failure(
                SeriesMigrationReason::MissingTargetReference,
                "Mapped Work or Series dependency does not exist."
            );
        }

        $edgeId = self::membershipTargetId($workId, $seriesId);
        $mapped = $this->mappedTarget(
            $run,
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "work_series_membership"
        );
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
                $observation
            );
            if ($mapped !== $edgeId || !$this->hasExactMembership($workId, $seriesId, $plan)) {
                throw $this->failure(
                    SeriesMigrationReason::DivergentReplay,
                    "Mapped Work-Series membership no longer matches canonical state."
                );
            }
            $this->processPromotedPreservation($run, $observation, $plan);
            return MigrationRecordOutcome::mapped([
                $this->mapping("work_series_membership", $edgeId, MappingDisposition::Reused),
            ]);
        }

        foreach ($this->series->membershipsForWorks([$workId])[$workId->value()] ?? [] as $membership) {
            if ($membership->seriesId()->value() !== $seriesId->value()) {
                continue;
            }
            if ($membership->position()->value() !== $plan->position()->value()) {
                throw $this->failure(
                    SeriesMigrationReason::MembershipConflict,
                    "Existing Work-Series membership has an incompatible position."
                );
            }
            $this->processPromotedPreservation($run, $observation, $plan);
            return MigrationRecordOutcome::mapped([
                $this->mapping("work_series_membership", $edgeId, MappingDisposition::Reused),
            ]);
        }

        try {
            $this->series->addMembership(new WorkSeriesMembership(
                $workId,
                $seriesId,
                $plan->position()
            ));
        } catch (CatalogRecordAlreadyExists $exception) {
            throw $this->failure(
                SeriesMigrationReason::MembershipConflict,
                "Work-Series membership conflicted with canonical state.",
                $exception
            );
        }
        $this->processPromotedPreservation($run, $observation, $plan);
        return MigrationRecordOutcome::mapped([
            $this->mapping("work_series_membership", $edgeId, MappingDisposition::Created),
        ]);
    }

    private function processPromotedPreservation(
        MigrationRun $run,
        SourceObservation $observation,
        CatalogWorkSeriesPlan $plan
    ): void {
        $expected = $plan->promotedPriorPreservation();
        if (!$expected instanceof PreservedSourceEvidencePlan) {
            return;
        }
        if (
            $this->promotionPolicy === null
            || !$this->promotionPolicy->admits($expected, $observation, $run)
        ) {
            throw $this->failure(
                SeriesMigrationReason::DivergentReplay,
                "Series preservation promotion is outside the admitted contract."
            );
        }
        $matches = $this->ledger->priorPreservations(
            $run->targetUserId()->value(),
            $run->targetLibraryId()->value(),
            $run->sourceFamily(),
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
            $expected->sourceIdentity(),
            true
        );
        if ($matches === []) {
            return;
        }

        $payload = DeterministicJson::encode($expected->canonicalPayload());
        $evidence = \Biblio\Core\Application\Migration\MigrationEvidence::canonicalJson(
            $expected->evidenceDescriptor()
        );
        foreach ($matches as $match) {
            if (
                !hash_equals($expected->manifestSha256(), $match->sourceSnapshot())
                || $expected->sourceVersion() !== $match->sourceVersion()
                || !hash_equals(hash("sha256", $payload), $match->payloadHash())
                || !hash_equals($payload, $match->payloadJson())
                || $expected->reasonCode() !== $match->reasonCode()
                || $expected->reasonCode() !== $match->preservationReason()
                || !hash_equals($evidence, $match->evidenceJson())
                || $expected->locator() !== $match->evidenceReference()
            ) {
                throw $this->failure(
                    SeriesMigrationReason::DivergentReplay,
                    "Prior contained-Series preservation diverges from the approved promotion."
                );
            }
        }
        $this->ledger->markPreservationsProcessed(
            $matches,
            $observation->createdAt()
        );
    }

    public static function targetSeriesId(string $sourceId): SeriesId
    {
        return new SeriesId("series-" . hash("sha256", "migration-series-v1\0" . $sourceId));
    }

    public static function membershipTargetId(WorkId $workId, SeriesId $seriesId): string
    {
        return "work-series-" . hash("sha256", implode("\0", [
            "work-series-v1",
            $workId->value(),
            $seriesId->value(),
        ]));
    }

    private function hasExactMembership(WorkId $workId, SeriesId $seriesId, CatalogWorkSeriesPlan $plan): bool
    {
        foreach ($this->series->membershipsForWorks([$workId])[$workId->value()] ?? [] as $membership) {
            if (
                $membership->seriesId()->value() === $seriesId->value()
                && $membership->position()->value() === $plan->position()->value()
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
                SeriesMigrationReason::MissingTargetReference,
                "Migration run is unavailable."
            );
    }

    private function requireMappedTarget(MigrationRun $run, string $sourceType, string $sourceId, string $targetType): string
    {
        return $this->mappedTarget($run, $sourceType, $sourceId, $targetType)
            ?? throw $this->failure(
                SeriesMigrationReason::MissingTargetReference,
                "Required Series migration dependency has no committed mapping."
            );
    }

    private function assertCommittedMappingMatchesPayload(MigrationRun $run, string $sourceType, SourceObservation $observation): void
    {
        foreach ($this->ledger->sourceTargets($run, $sourceType, $observation->sourceId()) as $trace) {
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    SeriesMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a committed Series mapping."
                );
            }
        }
    }

    private function mappedTarget(MigrationRun $run, string $sourceType, string $sourceId, string $targetType): ?string
    {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if ($trace->targetType() !== $targetType) {
                throw $this->failure(
                    SeriesMigrationReason::DivergentReplay,
                    "Logical Series source identity has an unexpected target mapping type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                SeriesMigrationReason::DivergentReplay,
                "Logical Series source identity has conflicting target mappings."
            );
        }
        return array_key_first($targets);
    }

    private function mapping(string $targetType, string $targetId, MappingDisposition $disposition): MigrationTargetMapping
    {
        return new MigrationTargetMapping($targetType, $targetId, $disposition);
    }

    private function failure(SeriesMigrationReason $reason, string $message, ?Throwable $previous = null): SeriesMigrationFailure
    {
        return new SeriesMigrationFailure($reason, $message, $previous);
    }
}
