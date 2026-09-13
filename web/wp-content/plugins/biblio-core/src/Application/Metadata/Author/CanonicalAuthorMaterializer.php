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
                $this->observe($existingCredit->id(), $input);
                return $this->positionConflictResult(
                    $mappedAuthorId,
                    $existingCredit->id(),
                    $mappedAuthorId === null
                        ? AuthorMaterializationWriteDisposition::NotWritten
                        : AuthorMaterializationWriteDisposition::Reused,
                    AuthorMaterializationWriteDisposition::Reused
                );
            }

            $creditAuthorId = $existingCredit->authorId()
                ?? throw new PersistenceException(
                    "Linked Author contributor credit has no Author."
                );
            if ($mappedAuthorId !== null
                && $mappedAuthorId->value() !== $creditAuthorId->value()) {
                $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::IdentityConflict,
                    $now
                );
                return $this->identityConflictResult(
                    $mappedAuthorId,
                    $existingCredit->id()
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
                        $this->observe($existingCredit->id(), $input);
                        $this->flagReviewReason(
                            $existingCredit,
                            AuthorCreditReviewReason::IdentityConflict,
                            $now
                        );
                        return $this->identityConflictResult(
                            $winner,
                            $existingCredit->id()
                        );
                    } else {
                        throw new AuthorProviderClaimRace();
                    }
                }
            }
            $this->requireResolvedAuthor($creditAuthorId);

            $edgeState = $this->edgeState($input, $creditAuthorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $this->observe($existingCredit->id(), $input);
                $this->flagReviewReason(
                    $existingCredit,
                    AuthorCreditReviewReason::StructuralAmbiguity,
                    $now
                );
                return $this->positionConflictResult(
                    $creditAuthorId,
                    $existingCredit->id(),
                    $claimDisposition,
                    AuthorMaterializationWriteDisposition::Reused
                );
            }

            $edgeDisposition = AuthorMaterializationWriteDisposition::Reused;
            if ($edgeState === ContributorEdgeState::Absent) {
                $this->addEdge($input, $creditAuthorId);
                $edgeDisposition = AuthorMaterializationWriteDisposition::Created;
            }
            $this->observe($existingCredit->id(), $input);
            return $this->materializedResult(
                $creditAuthorId,
                $existingCredit->id(),
                AuthorMaterializationWriteDisposition::Reused,
                $claimDisposition,
                AuthorMaterializationWriteDisposition::Reused,
                $edgeDisposition
            );
        }

        $creditId = $this->ids->nextCreditId();
        if ($mappedAuthorId !== null) {
            $this->requireResolvedAuthor($mappedAuthorId);
            $edgeState = $this->edgeState($input, $mappedAuthorId);
            if ($edgeState === ContributorEdgeState::Conflict) {
                $this->createUnresolvedCredit($creditId, $key, $input, $now);
                $this->observe($creditId, $input);
                return $this->positionConflictResult(
                    $mappedAuthorId,
                    $creditId,
                    AuthorMaterializationWriteDisposition::Reused,
                    AuthorMaterializationWriteDisposition::Created
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
            $this->observe($storedCredit->id(), $input);

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
                $edgeDisposition
            );
        }

        if ($this->edgeState($input, null) === ContributorEdgeState::Conflict) {
            $this->createUnresolvedCredit($creditId, $key, $input, $now);
            $this->observe($creditId, $input);
            return $this->positionConflictResult(
                null,
                $creditId,
                AuthorMaterializationWriteDisposition::NotWritten,
                AuthorMaterializationWriteDisposition::Created
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
        $this->observe($storedCredit->id(), $input);
        $this->addEdge($input, $authorId);

        return $this->materializedResult(
            $authorId,
            $storedCredit->id(),
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::Created,
            AuthorMaterializationWriteDisposition::Created
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
        StrongOpenLibraryAuthorCredit $input,
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
        StrongOpenLibraryAuthorCredit $input,
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
        StrongOpenLibraryAuthorCredit $input
    ): void {
        $this->credits->observeEvidence(AuthorCreditEvidence::provider(
            $creditId,
            OpenLibraryAuthorId::PROVIDER_KEY,
            $input->sourceType()->value,
            $input->sourceRecordId(),
            $input->providerAuthorId()->value(),
            $input->observedDisplayName(),
            $input->role(),
            $input->position(),
            $input->observedAt()
        ));
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
        StrongOpenLibraryAuthorCredit $input,
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
        StrongOpenLibraryAuthorCredit $input,
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

    private function materializedResult(
        AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $author,
        AuthorMaterializationWriteDisposition $claim,
        AuthorMaterializationWriteDisposition $credit,
        AuthorMaterializationWriteDisposition $edge
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::Materialized,
            $authorId,
            $creditId,
            $author,
            $claim,
            $credit,
            $edge
        );
    }

    private function identityConflictResult(
        AuthorId $authorId,
        AuthorContributorCreditId $creditId
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::IdentityConflict,
            $authorId,
            $creditId,
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::Reused,
            AuthorMaterializationWriteDisposition::NotWritten
        );
    }

    private function positionConflictResult(
        ?AuthorId $authorId,
        AuthorContributorCreditId $creditId,
        AuthorMaterializationWriteDisposition $claim,
        AuthorMaterializationWriteDisposition $credit
    ): CanonicalAuthorMaterializationResult {
        return new CanonicalAuthorMaterializationResult(
            AuthorMaterializationStatus::PositionConflict,
            $authorId,
            $creditId,
            $authorId === null
                ? AuthorMaterializationWriteDisposition::NotWritten
                : AuthorMaterializationWriteDisposition::Reused,
            $claim,
            $credit,
            AuthorMaterializationWriteDisposition::NotWritten
        );
    }
}
