<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldEvidence;
use Biblio\Core\Application\Metadata\MetadataFieldReviewRepository;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupSnapshotUnavailable;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\CanonicalIsbnAlreadyClaimed;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WritableEditionRepository;
use Biblio\Core\Catalog\WritableWorkRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;

final readonly class BibliographicMaterializationService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private BibliographicMaterializationAuthorization $authorization,
        private BibliographicDiscoverySnapshotRepository $snapshots,
        private BibliographicProviderIdentityRepository $providerIdentities,
        private LocalEditionResolver $localEditions,
        private EditionIdentifierClaimRepository $isbnClaims,
        private WritableWorkRepository $works,
        private WritableEditionRepository $editions,
        private MetadataFieldReviewRepository $reviews,
        private BibliographicRecordIdGenerator $ids,
        private MetadataClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function materialize(
        MetadataLookupId $discoveryId,
        MetadataCandidateId $candidateId,
        BibliographicMaterializationIntent $intent
    ): BibliographicMaterializationResult {
        $actor = $this->authenticatedUser->requireUserId();
        $this->authorization->assertAllowed($actor);
        $candidate = $this->snapshots->candidateForMaterialization(
            $discoveryId,
            $candidateId,
            $actor,
            $this->clock->now()
        ) ?? throw new MetadataLookupSnapshotUnavailable();

        if ($intent === BibliographicMaterializationIntent::WorkOnly
            && !$candidate->canAddWorkOnly()) {
            throw new ValidationException("Candidate cannot be materialized as Work only.");
        }
        if ($intent === BibliographicMaterializationIntent::WorkAndEdition
            && !$candidate->canAddEditionSpecific()) {
            throw new ValidationException("Candidate cannot be materialized as an Edition.");
        }

        try {
            return $this->transactions->run(
                fn (): BibliographicMaterializationResult =>
                    $this->materializeOnce($candidate, $intent)
            );
        } catch (BibliographicProviderIdentityConflict|CanonicalIsbnAlreadyClaimed) {
            return $this->transactions->run(
                fn (): BibliographicMaterializationResult =>
                    $this->materializeOnce($candidate, $intent)
            );
        }
    }

    private function materializeOnce(
        BibliographicDiscoveryCandidate $candidate,
        BibliographicMaterializationIntent $intent
    ): BibliographicMaterializationResult {
        $provider = $candidate->providerKey()
            ?? throw new ValidationException("Local candidates are already materialized.");
        $recordId = $candidate->providerRecordId()
            ?? throw new ValidationException("Candidate lacks provider identity.");
        $sourceType = $candidate->type() === BibliographicCandidateType::ExternalWork
            ? "work" : "edition";

        if ($intent === BibliographicMaterializationIntent::WorkAndEdition) {
            $mappedEditionId = $this->providerIdentities->findEdition($provider, $recordId);
            if ($mappedEditionId !== null) {
                $edition = $this->editions->find($mappedEditionId)
                    ?? throw new PersistenceException("Mapped Edition is missing.");
                if ($candidate->isbn() !== null) {
                    $resolution = $this->localEditions->resolveIdentity($candidate->isbn());
                    if ($resolution->type() === LocalEditionResolutionType::LocalAmbiguous
                        || ($resolution->type() === LocalEditionResolutionType::LocalExact
                            && !$resolution->requireEdition()->id()->equals($edition->id()))) {
                        throw new ValidationException(
                            "Provider Edition and canonical ISBN identities conflict."
                        );
                    }
                }
                if ($candidate->providerWorkId() !== null) {
                    $providerWorkId = $this->providerIdentities->findWork(
                        $provider,
                        "work",
                        $candidate->providerWorkId()
                    );
                    if ($providerWorkId !== null
                        && !$providerWorkId->equals($edition->workId())) {
                        throw new ValidationException(
                            "Provider Work and Edition identities conflict."
                        );
                    }
                    $this->providerIdentities->claimWork(
                        $provider,
                        "work",
                        $candidate->providerWorkId(),
                        $edition->workId()
                    );
                }
                $editionRecordWorkId = $this->providerIdentities->findWork(
                    $provider,
                    $sourceType,
                    $recordId
                );
                if ($editionRecordWorkId !== null
                    && !$editionRecordWorkId->equals($edition->workId())) {
                    throw new ValidationException(
                        "Provider Edition and Work identities conflict."
                    );
                }
                $this->providerIdentities->claimWork(
                    $provider,
                    $sourceType,
                    $recordId,
                    $edition->workId()
                );
                $work = $this->requireWork($edition->workId());
                $this->recordEvidence($candidate, $work, $edition);
                return new BibliographicMaterializationResult($work, $edition, true);
            }
        }

        $mappedWorkId = $candidate->providerWorkId() === null
            ? null
            : $this->providerIdentities->findWork(
                $provider, "work", $candidate->providerWorkId()
            );
        $mappedWorkId ??= $this->providerIdentities->findWork(
            $provider, $sourceType, $recordId
        );

        $isbnEdition = null;
        if ($intent === BibliographicMaterializationIntent::WorkAndEdition
            && $candidate->isbn() !== null) {
            $resolution = $this->localEditions->resolveIdentity($candidate->isbn());
            if ($resolution->type() === LocalEditionResolutionType::LocalAmbiguous) {
                throw new ValidationException("Canonical ISBN resolves ambiguously.");
            }
            if ($resolution->type() === LocalEditionResolutionType::LocalExact) {
                $isbnEdition = $resolution->requireEdition();
                if ($mappedWorkId !== null && !$mappedWorkId->equals($isbnEdition->workId())) {
                    throw new ValidationException("Provider Work and canonical ISBN identities conflict.");
                }
                $mappedWorkId = $isbnEdition->workId();
            }
        }

        $reused = $mappedWorkId !== null || $isbnEdition !== null;
        if ($mappedWorkId === null) {
            $work = new Work($this->ids->nextWorkId(), $candidate->title());
            $this->works->add($work);
        } else {
            $work = $this->requireWork($mappedWorkId);
        }

        if ($candidate->providerWorkId() !== null) {
            $this->providerIdentities->claimWork(
                $provider, "work", $candidate->providerWorkId(), $work->id()
            );
        }
        $this->providerIdentities->claimWork(
            $provider, $sourceType, $recordId, $work->id()
        );

        if ($intent === BibliographicMaterializationIntent::WorkOnly) {
            $this->recordEvidence($candidate, $work, null);
            return new BibliographicMaterializationResult($work, null, $reused);
        }

        if ($isbnEdition !== null) {
            $edition = $isbnEdition;
        } else {
            $edition = new Edition(
                $this->ids->nextEditionId(),
                $work->id(),
                $candidate->title(),
                $candidate->isbn()?->metadata() ?? EditionIsbnMetadata::unknown()
            );
            $this->editions->add($edition);
            if ($candidate->isbn() !== null) {
                $this->isbnClaims->claim($candidate->isbn()->isbn13(), $edition->id());
            }
        }
        $this->providerIdentities->claimEdition($provider, $recordId, $edition->id());
        $this->recordEvidence($candidate, $work, $edition);
        return new BibliographicMaterializationResult($work, $edition, $reused);
    }

    private function recordEvidence(
        BibliographicDiscoveryCandidate $candidate,
        Work $work,
        ?Edition $edition
    ): void {
        $evidence = MetadataFieldEvidence::fromDiscoveryCandidate($candidate);
        if ($candidate->type() === BibliographicCandidateType::ExternalWork) {
            $this->observe(
                MetadataRecordId::forWork($work->id()),
                $evidence,
                [
                    MetadataField::Title->value => $candidate->title(),
                    MetadataField::Contributors->value => $candidate->contributors(),
                ]
            );
            return;
        }
        if ($edition === null) {
            $this->observe(
                MetadataRecordId::forWork($work->id()),
                $evidence,
                [MetadataField::Contributors->value => $candidate->contributors()]
            );
            return;
        }
        $this->observe(
            MetadataRecordId::forEdition($edition->id()),
            $evidence,
            [
                MetadataField::Title->value => $candidate->title(),
                MetadataField::Subtitle->value => $candidate->subtitle(),
                MetadataField::Languages->value => $candidate->languages(),
                MetadataField::Publishers->value => $candidate->publishers(),
                MetadataField::PublicationDate->value => $candidate->publicationDate(),
                MetadataField::PageCount->value => $candidate->pageCount(),
                MetadataField::Format->value => $candidate->format(),
            ]
        );
        $this->observe(
            MetadataRecordId::forEditionEvidence($edition->id()),
            $evidence,
            [MetadataField::Contributors->value => $candidate->contributors()]
        );
    }

    /** @param array<string,string|int|list<string>|null> $values */
    private function observe(
        MetadataRecordId $recordId,
        MetadataFieldEvidence $evidence,
        array $values
    ): void {
        foreach ($values as $fieldKey => $raw) {
            if ($raw === null || $raw === []) { continue; }
            $field = MetadataField::from($fieldKey);
            $review = $this->reviews->findForUpdate(
                $recordId, $field, $evidence->lastRetrievedAt()
            );
            $review->observe(
                new MetadataFieldValue($raw),
                clone $evidence,
                $evidence->lastRetrievedAt()
            );
            $this->reviews->save($review);
        }
    }

    private function requireWork(\Biblio\Core\Catalog\WorkId $id): Work
    {
        return $this->works->find($id)
            ?? throw new PersistenceException("Materialized Work is missing.");
    }
}
