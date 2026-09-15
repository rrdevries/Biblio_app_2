<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Catalog\{
    Author,
    AuthorDisplayNameStatus,
    AuthorId,
    AuthorIdentityStatus,
    CatalogRecordAlreadyExists,
    WorkContributor,
    WritableAuthorRepository
};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;

final readonly class CanonicalAuthorMaterializer
{
    public function __construct(
        private WritableAuthorRepository $authors,
        private AuthorProviderIdentityRepository $providerIdentities,
        private AuthorContributorCreditRepository $credits,
        private CanonicalAuthorMaterializationIdGenerator $ids,
        private MetadataClock $clock
    ) {
    }

    /**
     * This method must run inside the caller-owned transaction. Expected race
     * signals deliberately escape so the caller can retry the complete operation.
     */
    public function materializeStrongOpenLibraryAuthor(
        StrongOpenLibraryAuthorCredit $input
    ): CanonicalAuthorMaterializationResult {
        $now = $this->clock->now();
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $existingCredit = $this->credits->findByKey($key);
        $providerRecordId = $input->providerAuthorId()->value();
        $mappedAuthorId = $this->providerIdentities->findAuthor(
            OpenLibraryAuthorId::PROVIDER_KEY,
            $providerRecordId
        );

        if ($existingCredit !== null) {
            if ($existingCredit->status() === AuthorContributorCreditStatus::Unresolved) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                return $this->positionConflictResult(
                    $mappedAuthorId,
                    $existingCredit->id(),
                    $mappedAuthorId === null
                        ? AuthorMaterializationWriteDisposition::NotWritten
                        : AuthorMaterializationWriteDisposition::Reused,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }

            $creditAuthorId = $existingCredit->authorId()
                ?? throw new PersistenceException(
                    "Linked Author contributor credit has no Author."
                );
            if ($mappedAuthorId !== null
                && $mappedAuthorId->value() !== $creditAuthorId->value()) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::IdentityConflict,
                    $now
                );
                return $this->identityConflictResult(
                    $mappedAuthorId,
                    $existingCredit->id(),
                    $evidenceDisposition
                );
            }

            $claimDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($mappedAuthorId === null) {
                try {
                    $this->providerIdentities->claimAuthor(
                        OpenLibraryAuthorId::PROVIDER_KEY,
                        $providerRecordId,
                        $creditAuthorId
                    );
                    $claimDisposition = AuthorMaterializationWriteDisposition::Created;
                } catch (AuthorProviderIdentityConflict) {
                    $winner = $this->providerIdentities->findAuthor(
                        OpenLibraryAuthorId::PROVIDER_KEY,
                        $providerRecordId
                    );
                    if ($winner?->value() === $creditAuthorId->value()) {
                        $claimDisposition = AuthorMaterializationWriteDisposition::Reused;
                    } elseif ($winner !== null) {
                        $evidenceDisposition = $this->observe(
                            $existingCredit->id(),
                            $input
                        );
                        $this->flagReviewReason(
                            $existingCredit,
                            AuthorCreditReviewReason::IdentityConflict,
                            $now
                        );
                        return $this->identityConflictResult(
                            $winner,
                            $existingCredit->id(),
                            $evidenceDisposition
                        );
                    } else {
                        throw new AuthorProviderClaimRace();
                    }
                }
            }
            $this->requireResolvedAuthor($creditAuthorId);

            $edgeState = $this->edgeState($input, $creditAuthorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::StructuralAmbiguity,
                    $now
                );
                return $this->positionConflictResult(
                    $creditAuthorId,
                    $existingCredit->id(),
                    $claimDisposition,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }

            $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($edgeState === ContributorEdgeState::Absent) {
                $this->addEdge($input, $creditAuthorId);
                $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
            }
            $evidenceDisposition = $this->observe($existingCredit->id(), $input);
            return $this->materializedResult(
                $creditAuthorId,
                $existingCredit->id(),
                AuthorMaterializationWriteDisposition::Reused,
                $claimDisposition,
                AuthorMaterializationWriteDisposition::Reused,
                $evidenceDisposition,
                $edgeDisposition
            );
        }

        $creditId = $this->ids->nextCreditId();
        if ($mappedAuthorId !== null) {
            $this->requireResolvedAuthor($mappedAuthorId);
            $edgeState = $this->edgeState($input, $mappedAuthorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $this->createUnresolvedCredit($creditId, $key, $input, $now);
                $evidenceDisposition = $this->observe($creditId, $input);
                return $this->positionConflictResult(
                    $mappedAuthorId,
                    $creditId,
                    AuthorMaterializationWriteDisposition::Reused,
                    AuthorMaterializationWriteDisposition::Created,
                    $evidenceDisposition
                );
            }

            $storedCredit = $this->createLinkedCredit(
                $creditId,
                $key,
                $input,
                $mappedAuthorId,
                $now
            );
            $creditDisposition = $storedCredit->id()->value() === $creditId->value()
                ? AuthorMaterializationWriteDisposition::Created
                : AuthorMaterializationWriteDisposition::Reused;
            $evidenceDisposition = $this->observe($storedCredit->id(), $input);

            $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($edgeState === ContributorEdgeState::Absent) {
                $this->addEdge($input, $mappedAuthorId);
                $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
            }
            return $this->materializedResult(
                $mappedAuthorId,
                $storedCredit->id(),
                AuthorMaterializationWriteDisposition::Reused,
                AuthorMaterializationWriteDisposition::Reused,
                $creditDisposition,
                $evidenceDisposition,
                $edgeDisposition
            );
        }

        if ($this->edgeState($input, null) === ContributorEdgeState::Conflict) {
            $this->createUnresolvedCredit($creditId, $key, $input, $now);
            $evidenceDisposition = $this->observe($creditId, $input);
            return $this->positionConflictResult(
                null,
                $creditId,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Created,
                $evidenceDisposition
            );
        }

        $authorId = $this->ids->nextAuthorId();
        $this->authors->add(new Author(
            $authorId,
            AuthorContributorCreditKey::normalizeObservedName(
                $input->observedDisplayName()
            ),
            AuthorIdentityStatus::Resolved,
            AuthorDisplayNameStatus::Observed
        ));
        try {
            $this->providerIdentities->claimAuthor(
                OpenLibraryAuthorId::PROVIDER_KEY,
                $providerRecordId,
                $authorId
            );
        } catch (AuthorProviderIdentityConflict) {
            throw new AuthorProviderClaimRace();
        }
        $storedCredit = $this->createLinkedCredit(
            $creditId,
            $key,
            $input,
            $authorId,
            $now
        );
        $evidenceDisposition = $this->observe($storedCredit->id(), $input);
        $this->addEdge($input, $authorId);

        return $this->materializedResult(
            $authorId,
            $storedCredit->id(),
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::Created,
            $evidenceDisposition,
            AuthorMaterializationWriteDisposition::Created
        );
    }

    /**
     * This method must run inside the caller-owned transaction. Expected race
     * signals deliberately escape so the caller can retry the complete operation.
     */
    public function materializeNameOnlyAuthor(
        NameOnlyAuthorMaterializationCredit $input
    ): CanonicalAuthorMaterializationResult {
        $now = $this->clock->now();
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $existingCredit = $this->credits->findByKey($key);

        if ($existingCredit !== null) {
            if ($existingCredit->status() === AuthorContributorCreditStatus::Unresolved) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                return $this->positionConflictResult(
                    null,
                    $existingCredit->id(),
                    AuthorMaterializationWriteDisposition::NotWritten,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }

            $authorId = $existingCredit->authorId()
                ?? throw new PersistenceException(
                    "Linked Author contributor credit has no Author."
                );
            $this->authors->find($authorId)
                ?? throw new PersistenceException(
                    "Linked Author contributor credit points to a missing Author."
                );
            $edgeState = $this->edgeState($input, $authorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::StructuralAmbiguity,
                    $now
                );
                return $this->positionConflictResult(
                    $authorId,
                    $existingCredit->id(),
                    AuthorMaterializationWriteDisposition::NotWritten,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }

            $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($edgeState === ContributorEdgeState::Absent) {
                $this->addEdge($input, $authorId);
                $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
            }
            $evidenceDisposition = $this->observe($existingCredit->id(), $input);
            return $this->materializedResult(
                $authorId,
                $existingCredit->id(),
                AuthorMaterializationWriteDisposition::Reused,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Reused,
                $evidenceDisposition,
                $edgeDisposition
            );
        }

        $creditId = $this->ids->nextCreditId();
        if ($this->edgeState($input, null) === ContributorEdgeState::Conflict) {
            try {
                $this->createUnresolvedCredit($creditId, $key, $input, $now);
            } catch (AuthorContributorCreditConflict) {
                throw new AuthorContributorCreditRace();
            }
            $evidenceDisposition = $this->observe($creditId, $input);
            return $this->positionConflictResult(
                null,
                $creditId,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Created,
                $evidenceDisposition
            );
        }

        $authorId = $this->ids->nextAuthorId();
        $this->authors->add(new Author(
            $authorId,
            AuthorContributorCreditKey::normalizeObservedName(
                $input->observedDisplayName()
            ),
            AuthorIdentityStatus::Provisional,
            AuthorDisplayNameStatus::Observed
        ));
        try {
            $storedCredit = $this->createLinkedCredit(
                $creditId,
                $key,
                $input,
                $authorId,
                $now
            );
        } catch (AuthorContributorCreditConflict) {
            throw new AuthorContributorCreditRace();
        }
        $evidenceDisposition = $this->observe($storedCredit->id(), $input);
        $this->addEdge($input, $authorId);

        return $this->materializedResult(
            $authorId,
            $storedCredit->id(),
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::NotWritten,
            AuthorMaterializationWriteDisposition::Created,
            $evidenceDisposition,
            AuthorMaterializationWriteDisposition::Created
        );
    }

    /**
     * Links one reviewed migration occurrence to an exact already-mapped
     * canonical Author. This joins the caller-owned migration transaction.
     */
    public function materializeMigrationAuthor(
        MigrationAuthorCredit $input
    ): CanonicalAuthorMaterializationResult {
        $now = $this->clock->now();
        $authorId = $input->authorId();
        $this->authors->find($authorId)
            ?? throw new PersistenceException(
                "Mapped migration Author does not exist."
            );
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $existingCredit = $this->credits->findByKey($key);

        if ($existingCredit !== null) {
            if ($existingCredit->status() === AuthorContributorCreditStatus::Unresolved) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                return $this->positionConflictResult(
                    $authorId,
                    $existingCredit->id(),
                    AuthorMaterializationWriteDisposition::NotWritten,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }
            $creditAuthorId = $existingCredit->authorId()
                ?? throw new PersistenceException(
                    "Linked migration Author credit has no Author."
                );
            if ($creditAuthorId->value() !== $authorId->value()) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                return $this->migrationIdentityConflictResult(
                    $creditAuthorId,
                    $existingCredit->id(),
                    $evidenceDisposition
                );
            }

            $edgeState = $this->edgeState($input, $authorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $evidenceDisposition = $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::StructuralAmbiguity,
                    $now
                );
                return $this->positionConflictResult(
                    $authorId,
                    $existingCredit->id(),
                    AuthorMaterializationWriteDisposition::NotWritten,
                    AuthorMaterializationWriteDisposition::Reused,
                    $evidenceDisposition
                );
            }

            $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($edgeState === ContributorEdgeState::Absent) {
                $this->addEdge($input, $authorId);
                $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
            }
            $evidenceDisposition = $this->observe($existingCredit->id(), $input);
            return $this->materializedResult(
                $authorId,
                $existingCredit->id(),
                AuthorMaterializationWriteDisposition::Reused,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Reused,
                $evidenceDisposition,
                $edgeDisposition
            );
        }

        $creditId = $this->ids->nextCreditId();
        if ($this->edgeState($input, $authorId) === ContributorEdgeState::Conflict) {
            $this->createUnresolvedCredit($creditId, $key, $input, $now);
            $evidenceDisposition = $this->observe($creditId, $input);
            return $this->positionConflictResult(
                $authorId,
                $creditId,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Created,
                $evidenceDisposition
            );
        }

        try {
            $storedCredit = $this->createLinkedCredit(
                $creditId,
                $key,
                $input,
                $authorId,
                $now
            );
        } catch (AuthorContributorCreditConflict) {
            throw new AuthorContributorCreditRace();
        }
        $evidenceDisposition = $this->observe($storedCredit->id(), $input);
        $edgeState = $this->edgeStateAfterCreditWrite($input, $authorId);
        if ($edgeState === ContributorEdgeState::Conflict) {
            $this->flagReviewReason(
                $storedCredit,
                AuthorCreditReviewReason::StructuralAmbiguity,
                $now
            );
            return $this->positionConflictResult(
                $authorId,
                $storedCredit->id(),
                AuthorMaterializationWriteDisposition::NotWritten,
                $storedCredit->id()->value() === $creditId->value()
                    ? AuthorMaterializationWriteDisposition::Created
                    : AuthorMaterializationWriteDisposition::Reused,
                $evidenceDisposition
            );
        }
        $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
        if ($edgeState === ContributorEdgeState::Absent) {
            $this->addEdge($input, $authorId);
            $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
        }

        return $this->materializedResult(
            $authorId,
            $storedCredit->id(),
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::NotWritten,
            $storedCredit->id()->value() === $creditId->value()
                ? AuthorMaterializationWriteDisposition::Created
                : AuthorMaterializationWriteDisposition::Reused,
            $evidenceDisposition,
            $edgeDisposition
        );
    }

    private function requireResolvedAuthor(AuthorId $authorId): Author
    {
        $author = $this->authors->find($authorId)
            ?? throw new PersistenceException(
                "Provider Author claim points to a missing canonical Author."
            );
        if ($author->identityStatus() === AuthorIdentityStatus::Resolved) {
            return $author;
        }

        $replacement = new Author(
            $author->id(),
            $author->displayName(),
            AuthorIdentityStatus::Resolved,
            $author->displayNameStatus(),
            $author->version()->next()
        );
        if ($this->authors->replaceIfVersionMatches(
            $replacement,
            $author->version()
        )) {
            return $replacement;
        }

        $current = $this->authors->find($authorId);
        if ($current->identityStatus() === AuthorIdentityStatus::Resolved) {
            return $current;
        }
        throw new AuthorIdentityPromotionRace();
    }

    private function createLinkedCredit(
        AuthorContributorCreditId $creditId,
        AuthorContributorCreditKey $key,
        AuthorMaterializationCredit $input,
        AuthorId $authorId,
        \DateTimeImmutable $now
    ): AuthorContributorCredit {
        return $this->credits->create(new AuthorContributorCredit(
            $creditId,
            $key,
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $authorId,
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
    }

    private function createUnresolvedCredit(
        AuthorContributorCreditId $creditId,
        AuthorContributorCreditKey $key,
        AuthorMaterializationCredit $input,
        \DateTimeImmutable $now
    ): void {
        $this->credits->create(new AuthorContributorCredit(
            $creditId,
            $key,
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            null,
            AuthorContributorCreditStatus::Unresolved,
            AuthorCreditReviewReason::StructuralAmbiguity,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
    }

    private function observe(
        AuthorContributorCreditId $creditId,
        AuthorMaterializationCredit $input
    ): AuthorMaterializationWriteDisposition {
        return $this->credits->observeEvidence($input->evidence($creditId));
    }

    private function flagReviewReason(
        AuthorContributorCredit $credit,
        AuthorCreditReviewReason $reason,
        \DateTimeImmutable $now
    ): void {
        if ($credit->reviewReason() === $reason) {
            return;
        }
        if ($credit->reviewReason() !== null) {
            return;
        }
        if ($this->credits->setReviewReasonIfVersionMatches(
            $credit->id(),
            $credit->version(),
            $reason,
            $now
        )) {
            return;
        }
        $current = $this->credits->findByKey($credit->key());
        if ($current?->reviewReason() === $reason) {
            return;
        }
        throw new AuthorContributorCreditRace();
    }

    private function edgeState(
        AuthorMaterializationCredit $input,
        ?AuthorId $authorId
    ): ContributorEdgeState {
        $contributors = $this->authors->contributorsForWorks([$input->workId()]);
        foreach ($contributors[$input->workId()->value()] ?? [] as $edge) {
            $sameAuthor = $authorId !== null
                && $edge->authorId()->value() === $authorId->value();
            $samePosition = $edge->position()->value()
                === $input->position()->value();
            if ($sameAuthor && $samePosition && $edge->role() === $input->role()) {
                return ContributorEdgeState::Exact;
            }
            if ($sameAuthor || $samePosition) {
                return ContributorEdgeState::Conflict;
            }
        }
        return ContributorEdgeState::Absent;
    }

    private function addEdge(
        AuthorMaterializationCredit $input,
        AuthorId $authorId
    ): void {
        try {
            $this->authors->addContributor(new WorkContributor(
                $input->workId(),
                $authorId,
                $input->role(),
                $input->position()
            ));
        } catch (CatalogRecordAlreadyExists) {
            throw new AuthorContributorPositionRace();
        }
    }

    /** Re-reads canonical edge state after credit/evidence writes. */
    private function edgeStateAfterCreditWrite(
        AuthorMaterializationCredit $input,
        AuthorId $authorId
    ): ContributorEdgeState {
        return $this->edgeState($input, $authorId);
    }

    private function materializedResult(
        AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $author,
        AuthorMaterializationWriteDisposition $claim,
        AuthorMaterializationWriteDisposition $credit,
        AuthorMaterializationWriteDisposition $evidence,
        AuthorMaterializationWriteDisposition $edge
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::Materialized,
            $authorId,
            $creditId,
            $this->authorIdentityStatus($authorId),
            $author,
            $claim,
            $credit,
            $evidence,
            $edge
        );
    }

    private function identityConflictResult(
        AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $evidence
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::IdentityConflict,
            $authorId,
            $creditId,
            $this->authorIdentityStatus($authorId),
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::Reused,
            $evidence,
            AuthorMaterializationWriteDisposition::NotWritten
        );
    }

    private function migrationIdentityConflictResult(
        AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $evidence
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::IdentityConflict,
            $authorId,
            $creditId,
            $this->authorIdentityStatus($authorId),
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::NotWritten,
            AuthorMaterializationWriteDisposition::Reused,
            $evidence,
            AuthorMaterializationWriteDisposition::NotWritten
        );
    }

    private function positionConflictResult(
        ?AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $claim,
        AuthorMaterializationWriteDisposition $credit,
        AuthorMaterializationWriteDisposition $evidence
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::PositionConflict,
            $authorId,
            $creditId,
            $authorId === null ? null : $this->authorIdentityStatus($authorId),
            $authorId === null
                ? AuthorMaterializationWriteDisposition::NotWritten
                : AuthorMaterializationWriteDisposition::Reused,
            $claim,
            $credit,
            $evidence,
            AuthorMaterializationWriteDisposition::NotWritten
        );
    }

    private function authorIdentityStatus(AuthorId $authorId): AuthorIdentityStatus
    {
        return $this->authors->find($authorId)?->identityStatus()
            ?? throw new PersistenceException(
                "Materialization result points to a missing canonical Author."
            );
    }
}
