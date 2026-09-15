<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Notes;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Application\Notes\PrivateNoteCreation;
use Biblio\Core\Catalog\{WorkId,WorkRepository};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Notes\{
    PrivateNote,
    PrivateNoteContent,
    PrivateNoteContentPolicy,
    PrivateNoteId,
    PrivateNoteVersion,
    WritablePrivateNoteRepository
};
use Biblio\Core\Reading\{ReadingRoundId,ReadingRoundRepository};

/** Joins the caller-owned MIG-FND transaction and never starts or retries it. */
final readonly class PrivateNoteMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private ReadingRoundRepository $rounds,
        private WritablePrivateNoteRepository $notes,
        private PrivateNoteCreation $creation,
        private PrivateNoteContentPolicy $contentPolicy
    ) {
    }

    public function apply(
        SourceObservation $observation,
        PrivateNotePlan $plan,
        LibraryId $planningLibraryId
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        $userId = $plan->targetUserId();
        if (!$run->targetUserId()->equals($userId)) {
            throw $this->failure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Private Note plan does not match the migration target user."
            );
        }
        if (!$run->targetLibraryId()->equals($planningLibraryId)) {
            throw $this->failure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Private Note planning Library does not match the migration run."
            );
        }
        if (!$this->users->isActive($userId)) {
            throw $this->failure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Private Note target user is not active."
            );
        }

        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work",
            PrivateNoteMigrationReason::MissingWorkMapping
        ));
        if ($this->works->find($workId) === null) {
            throw $this->failure(
                PrivateNoteMigrationReason::MissingWorkMapping,
                "Mapped Work dependency does not exist."
            );
        }

        $readingRoundId = $this->readingRound($run, $plan, $workId);
        $content = $this->content($plan);
        $mappedId = $this->mappedPrivateNote($run, $observation);
        if ($mappedId !== null) {
            $note = $this->notes->findForUser(
                new PrivateNoteId($mappedId),
                $userId
            );
            if (
                $note === null
                || !$this->sameNote(
                    $note,
                    $workId,
                    $readingRoundId,
                    $content,
                    $plan
                )
            ) {
                throw $this->failure(
                    PrivateNoteMigrationReason::DivergentReplay,
                    "Mapped Private Note no longer matches canonical state."
                );
            }
            $this->assertExclusiveSourceIdentity($run, $observation, $mappedId);

            return MigrationRecordOutcome::mapped([
                $this->mapping($mappedId, MappingDisposition::Reused),
            ]);
        }

        $note = $this->creation->create(
            $userId,
            static fn (PrivateNoteId $id): PrivateNote => new PrivateNote(
                $id,
                $userId,
                $workId,
                $readingRoundId,
                $content,
                $plan->createdAt(),
                $plan->updatedAt(),
                PrivateNoteVersion::initial()
            )
        );

        return MigrationRecordOutcome::mapped([
            $this->mapping($note->id()->value(), MappingDisposition::Created),
        ]);
    }

    private function readingRound(
        MigrationRun $run,
        PrivateNotePlan $plan,
        WorkId $workId
    ): ?ReadingRoundId {
        if ($plan->readingRoundSourceId() === null) {
            return null;
        }

        $roundId = new ReadingRoundId($this->requireMappedTarget(
            $run,
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            $plan->readingRoundSourceId(),
            "reading_round",
            PrivateNoteMigrationReason::MissingReadingRoundMapping
        ));
        $round = $this->rounds->findForUser($roundId, $plan->targetUserId());
        if ($round === null) {
            throw $this->failure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Mapped Reading Round is unavailable to the target user."
            );
        }
        if (!$round->workId()->equals($workId)) {
            throw $this->failure(
                PrivateNoteMigrationReason::WorkRoundMismatch,
                "Mapped Reading Round does not belong to the mapped Work."
            );
        }

        return $roundId;
    }

    private function content(PrivateNotePlan $plan): PrivateNoteContent
    {
        try {
            $validated = $this->contentPolicy->sanitize(
                $plan->content()->value()
            );
        } catch (ValidationException $failure) {
            throw $this->failure(
                PrivateNoteMigrationReason::InvalidNoteContent,
                "Private Note plan has invalid approved content.",
                $failure
            );
        }
        if (!$validated->equals($plan->content())) {
            throw $this->failure(
                PrivateNoteMigrationReason::InvalidNoteContent,
                "Private Note plan content is not in canonical storage form."
            );
        }

        return $validated;
    }

    private function mappedPrivateNote(
        MigrationRun $run,
        SourceObservation $observation
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            PrivateNoteMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->targetType() !== "private_note") {
                throw $this->failure(
                    PrivateNoteMigrationReason::ConflictingNoteMapping,
                    "Private Note source has an unexpected target mapping type."
                );
            }
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    PrivateNoteMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a Private Note mapping."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                PrivateNoteMigrationReason::ConflictingNoteMapping,
                "Private Note source has conflicting target mappings."
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
            "private_note",
            $targetId
        ) as $trace) {
            if (
                $trace->sourceType()
                    !== PrivateNoteMigrationParticipant::SOURCE_TYPE
                || $trace->sourceId() !== $observation->sourceId()
            ) {
                throw $this->failure(
                    PrivateNoteMigrationReason::ConflictingNoteMapping,
                    "Canonical Private Note is mapped from another source Note."
                );
            }
        }
    }

    private function sameNote(
        PrivateNote $note,
        WorkId $workId,
        ?ReadingRoundId $readingRoundId,
        PrivateNoteContent $content,
        PrivateNotePlan $plan
    ): bool {
        return $note->workId()->equals($workId)
            && $note->hasReadingRound($readingRoundId)
            && $note->content()->equals($content)
            && $note->createdAt() == $plan->createdAt()
            && $note->updatedAt() == $plan->updatedAt()
            && $note->version()->value() === 1;
    }

    private function requireRun(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Migration run is unavailable."
            );
    }

    private function requireMappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType,
        PrivateNoteMigrationReason $missingReason
    ): string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if ($trace->targetType() !== $targetType) {
                throw $this->failure(
                    PrivateNoteMigrationReason::WrongMappingTargetType,
                    "Required migration dependency has an unexpected target type."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                $missingReason,
                "Required migration dependency has no exact committed mapping."
            );
        }

        return (string) array_key_first($targets);
    }

    private function mapping(
        string $noteId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping(
            "private_note",
            $noteId,
            $disposition
        );
    }

    private function failure(
        PrivateNoteMigrationReason $reason,
        string $message,
        ?\Throwable $previous = null
    ): PrivateNoteMigrationFailure {
        return new PrivateNoteMigrationFailure($reason, $message, $previous);
    }
}
