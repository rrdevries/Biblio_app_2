<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditRepository,
    AuthorContributorCreditStatus,
    AuthorCreditEvidenceSourceKind,
    AuthorMaterializationStatus,
    AuthorMaterializationWriteDisposition,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer,
    MigrationAuthorCredit
};
use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Catalog\{
    Author,
    AuthorDisplayNameStatus,
    AuthorId,
    AuthorIdentityStatus,
    WorkId,
    WritableAuthorRepository,
    WritableWorkRepository
};
use Throwable;

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class AuthorMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private CanonicalAuthorMaterializationIdGenerator $ids,
        private WritableAuthorRepository $authors,
        private WritableWorkRepository $works,
        private AuthorContributorCreditRepository $credits,
        private CanonicalAuthorMaterializer $materializer
    ) {
    }

    public function applyAuthor(
        SourceObservation $observation,
        CatalogAuthorPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $mapped = $this->mappedTarget(
            $run,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "author",
            ["author"],
            AuthorMigrationReason::UnsafeAuthorMapping
        );
        $approved = $plan->approvedExistingAuthorId()?->value();
        if ($mapped !== null && $approved !== null && $mapped !== $approved) {
            throw $this->failure(
                AuthorMigrationReason::UnsafeAuthorMapping,
                "Author mapping conflicts with the approved canonical target."
            );
        }
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                $observation
            );
        }

        if ($mapped !== null || $approved !== null) {
            $authorId = new AuthorId($mapped ?? $approved);
            if ($this->authors->find($authorId) === null) {
                throw $this->failure(
                    AuthorMigrationReason::UnsafeAuthorMapping,
                    "Mapped Author target does not exist."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping(
                    "author",
                    $authorId->value(),
                    MappingDisposition::Reused
                ),
            ]);
        }

        $author = new Author(
            $this->ids->nextAuthorId(),
            $plan->displayName(),
            AuthorIdentityStatus::Provisional,
            AuthorDisplayNameStatus::Observed
        );
        $this->authors->add($author);
        return MigrationRecordOutcome::mapped([
            $this->mapping(
                "author",
                $author->id()->value(),
                MappingDisposition::Created
            ),
        ]);
    }

    public function applyContributor(
        SourceObservation $observation,
        CatalogWorkContributorPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        ));
        if ($this->works->find($workId) === null) {
            throw $this->failure(
                AuthorMigrationReason::MissingTargetReference,
                "Mapped Work dependency does not exist."
            );
        }
        $authorId = new AuthorId($this->requireMappedTarget(
            $run,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            $plan->authorSourceId(),
            "author"
        ));
        if ($this->authors->find($authorId) === null) {
            throw $this->failure(
                AuthorMigrationReason::MissingTargetReference,
                "Mapped Author dependency does not exist."
            );
        }

        $mappedCredit = $this->mappedTarget(
            $run,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "author_contributor_credit",
            ["author_contributor_credit", "work_contributor"]
        );
        $mappedEdge = $this->mappedTarget(
            $run,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "work_contributor",
            ["author_contributor_credit", "work_contributor"]
        );
        if ($mappedCredit !== null || $mappedEdge !== null) {
            if ($mappedCredit === null || $mappedEdge === null) {
                throw $this->failure(
                    AuthorMigrationReason::DivergentReplay,
                    "Contributor occurrence has an incomplete prior mapping."
                );
            }
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
                $observation
            );
            $credit = $this->credits->find(
                new AuthorContributorCreditId($mappedCredit)
            );
            $expectedEdge = $this->edgeId($workId, $authorId, $plan);
            if (
                $credit === null
                || !$this->sameCredit($credit, $workId, $authorId, $plan)
                || !$this->hasExactMigrationEvidence(
                    $run,
                    $observation,
                    $credit,
                    $plan
                )
                || $mappedEdge !== $expectedEdge
                || !$this->hasExactEdge($workId, $authorId, $plan)
            ) {
                throw $this->failure(
                    AuthorMigrationReason::DivergentReplay,
                    "Mapped contributor occurrence no longer matches canonical state."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping(
                    "author_contributor_credit",
                    $credit->id()->value(),
                    MappingDisposition::Reused
                ),
                $this->mapping(
                    "work_contributor",
                    $expectedEdge,
                    MappingDisposition::Reused
                ),
            ]);
        }

        $result = $this->materializer->materializeMigrationAuthor(
            new MigrationAuthorCredit(
                $workId,
                $authorId,
                $plan->role(),
                $plan->position(),
                $plan->observedDisplayName(),
                $observation->id(),
                $observation->createdAt()
            )
        );
        if ($result->status() === AuthorMaterializationStatus::IdentityConflict) {
            throw $this->failure(
                AuthorMigrationReason::ContributorIdentityConflict,
                "Contributor credit conflicts with the mapped Author."
            );
        }
        if ($result->status() === AuthorMaterializationStatus::PositionConflict) {
            throw $this->failure(
                AuthorMigrationReason::ContributorPositionConflict,
                "Contributor position conflicts with the canonical Work graph."
            );
        }
        if (
            $result->authorId()?->value() !== $authorId->value()
            || $result->providerClaim()
                !== AuthorMaterializationWriteDisposition::NotWritten
        ) {
            throw $this->failure(
                AuthorMigrationReason::ContributorIdentityConflict,
                "Migration contributor materialization returned an unsafe identity."
            );
        }

        return MigrationRecordOutcome::mapped([
            $this->mapping(
                "author_contributor_credit",
                $result->creditId()->value(),
                $this->mappingDisposition($result->credit())
            ),
            $this->mapping(
                "work_contributor",
                $this->edgeId($workId, $authorId, $plan),
                $this->mappingDisposition($result->contributorEdge())
            ),
        ]);
    }

    private function sameCredit(
        AuthorContributorCredit $credit,
        WorkId $workId,
        AuthorId $authorId,
        CatalogWorkContributorPlan $plan
    ): bool {
        return $credit->status() === AuthorContributorCreditStatus::Linked
            && $credit->workId()->value() === $workId->value()
            && $credit->authorId()?->value() === $authorId->value()
            && $credit->role() === $plan->role()
            && $credit->position()->value() === $plan->position()->value()
            && AuthorContributorCreditKey::normalizeObservedName(
                $credit->observedDisplayName()
            ) === $plan->observedDisplayName();
    }

    private function hasExactEdge(
        WorkId $workId,
        AuthorId $authorId,
        CatalogWorkContributorPlan $plan
    ): bool {
        foreach ($this->authors->contributorsForWorks([$workId])[$workId->value()] ?? [] as $edge) {
            if (
                $edge->authorId()->value() === $authorId->value()
                && $edge->role() === $plan->role()
                && $edge->position()->value() === $plan->position()->value()
            ) {
                return true;
            }
        }
        return false;
    }

    private function hasExactMigrationEvidence(
        MigrationRun $run,
        SourceObservation $observation,
        AuthorContributorCredit $credit,
        CatalogWorkContributorPlan $plan
    ): bool {
        $priorObservationIds = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if (
                $trace->targetType() === "author_contributor_credit"
                && $trace->targetId() === $credit->id()->value()
                && $trace->payloadHash() === $observation->payloadHash()
            ) {
                $priorObservationIds[$trace->observationId()] = true;
            }
        }
        foreach ($this->credits->evidenceForCredit($credit->id()) as $evidence) {
            if (
                $evidence->sourceKind() === AuthorCreditEvidenceSourceKind::Migration
                && isset($priorObservationIds[$evidence->sourceRecordId() ?? ""])
                && $evidence->role() === $plan->role()
                && $evidence->sourcePosition()->value() === $plan->position()->value()
                && AuthorContributorCreditKey::normalizeObservedName(
                    $evidence->observedDisplayName()
                ) === $plan->observedDisplayName()
            ) {
                return true;
            }
        }
        return false;
    }

    private function edgeId(
        WorkId $workId,
        AuthorId $authorId,
        CatalogWorkContributorPlan $plan
    ): string {
        return "work-contributor-" . hash("sha256", implode("\0", [
            "work-contributor-v1",
            $workId->value(),
            $authorId->value(),
            $plan->role()->value,
            (string) $plan->position()->value(),
        ]));
    }

    private function mappingDisposition(
        AuthorMaterializationWriteDisposition $disposition
    ): MappingDisposition {
        return match ($disposition) {
            AuthorMaterializationWriteDisposition::Created =>
                MappingDisposition::Created,
            AuthorMaterializationWriteDisposition::Reused =>
                MappingDisposition::Reused,
            AuthorMaterializationWriteDisposition::NotWritten =>
                throw $this->failure(
                    AuthorMigrationReason::DivergentReplay,
                    "Required contributor state was not written."
                ),
        };
    }

    private function run(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                AuthorMigrationReason::MissingTargetReference,
                "Migration run is unavailable."
            );
    }

    private function requireMappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType
    ): string {
        return $this->mappedTarget(
            $run,
            $sourceType,
            $sourceId,
            $targetType,
            [$targetType],
            AuthorMigrationReason::MissingTargetReference
        )
            ?? throw $this->failure(
                AuthorMigrationReason::MissingTargetReference,
                "Required Author migration dependency has no committed mapping."
            );
    }

    private function assertCommittedMappingMatchesPayload(
        MigrationRun $run,
        string $sourceType,
        SourceObservation $observation
    ): void {
        foreach ($this->ledger->sourceTargets(
            $run,
            $sourceType,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    AuthorMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse an Author mapping."
                );
            }
        }
    }

    /** @param list<string> $allowedTargetTypes */
    private function mappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType,
        array $allowedTargetTypes,
        AuthorMigrationReason $unexpectedReason = AuthorMigrationReason::DivergentReplay
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if (!in_array($trace->targetType(), $allowedTargetTypes, true)) {
                throw $this->failure(
                    $unexpectedReason,
                    "Logical source identity has an unexpected target mapping type."
                );
            }
            if ($trace->targetType() === $targetType) {
                $targets[$trace->targetId()] = true;
            }
        }
        if (count($targets) > 1) {
            throw $this->failure(
                AuthorMigrationReason::DivergentReplay,
                "Logical Author source identity has conflicting target mappings."
            );
        }
        return array_key_first($targets);
    }

    private function mapping(
        string $targetType,
        string $targetId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping($targetType, $targetId, $disposition);
    }

    private function failure(
        AuthorMigrationReason $reason,
        string $message,
        ?Throwable $previous = null
    ): AuthorMigrationFailure {
        return new AuthorMigrationFailure($reason, $message, $previous);
    }
}
