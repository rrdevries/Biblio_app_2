<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\AddLibraryItemTransactionParticipant;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;

final readonly class AddBookCommitEvidenceWriter implements
    AddLibraryItemTransactionParticipant
{
    public function __construct(
        private MetadataFieldReviewRepository $reviews,
        private UserObservedMetadataEvidenceRepository $observations,
        private EditionMetadataProvenanceRepository $provenance,
        private UserId $actorId,
        private LibraryId $libraryId,
        private AddBookObservedMetadata $observedMetadata,
        private ?MetadataCandidate $candidate,
        private DateTimeImmutable $observedAt
    ) {
    }

    public function apply(
        Work $work,
        Edition $edition,
        Item $item,
        bool $existingEdition
    ): void {
        $recordId = MetadataRecordId::forEdition($edition->id());
        $evidenceOnlyRecordId = MetadataRecordId::forEditionEvidence(
            $edition->id()
        );

        $this->seedUnconfirmedWhenUnknown(
            $recordId,
            MetadataField::Title,
            new MetadataFieldValue($edition->title())
        );

        if (!$existingEdition) {
            foreach ($this->observedMetadata->values() as $fieldKey => $value) {
                $field = MetadataField::tryFrom($fieldKey);
                if ($field !== null && $this->isEditionReviewField($field)) {
                    $this->seedUnconfirmedWhenUnknown($recordId, $field, $value);
                }
            }
        }

        if ($this->candidate !== null) {
            $this->ingestCandidate($recordId, $this->candidate);
            $this->ingestEvidenceOnlyCandidate(
                $evidenceOnlyRecordId,
                $this->candidate
            );
            $this->provenance->addReviewedCandidate(
                $edition->id(),
                $this->candidate,
                $this->candidateWasCorrected($this->candidate)
            );
        }

        foreach ($this->observedMetadata->values() as $fieldKey => $value) {
            $observedField = UserObservedMetadataField::from($fieldKey);
            $field = MetadataField::tryFrom($fieldKey);
            if ($field === null || !$this->isEditionReviewField($field)) {
                $this->recordObservation(
                    $recordId,
                    $edition,
                    $item,
                    $observedField,
                    $value,
                    false
                );
                continue;
            }
            $review = $this->reviews->findForUpdate(
                $recordId,
                $field,
                $this->observedAt
            );
            $canonical = $review->canonicalValue();
            $isCorrection = $existingEdition
                && $canonical !== null
                && !$canonical->equals($value);

            $review->observeUserValue($value, $this->observedAt);
            $this->reviews->save($review);
            $this->recordObservation(
                $recordId,
                $edition,
                $item,
                $observedField,
                $value,
                $isCorrection
            );
        }
    }

    private function recordObservation(
        MetadataRecordId $recordId,
        Edition $edition,
        Item $item,
        UserObservedMetadataField $field,
        MetadataFieldValue $value,
        bool $isCorrection
    ): void {
        $this->observations->add(new UserObservedMetadataEvidence(
            $recordId,
            $edition->id(),
            $item->id(),
            $this->libraryId,
            $this->actorId,
            $field,
            $value,
            $this->observedAt,
            MetadataObservationSource::PhysicalCopyAddBook,
            $isCorrection
        ));
    }

    private function seedUnconfirmedWhenUnknown(
        MetadataRecordId $recordId,
        MetadataField $field,
        MetadataFieldValue $value
    ): void {
        $review = $this->reviews->findForUpdate(
            $recordId,
            $field,
            $this->observedAt
        );
        if ($review->confirmationState() !== MetadataFieldConfirmationState::Unknown) {
            return;
        }

        $review->recordUnconfirmedValue($value, $this->observedAt);
        $this->reviews->save($review);
    }

    private function ingestCandidate(
        MetadataRecordId $recordId,
        MetadataCandidate $candidate
    ): void {
        $evidence = MetadataFieldEvidence::fromCandidate($candidate);
        foreach ($this->candidateValues($candidate) as [$field, $value]) {
            $review = $this->reviews->findForUpdate(
                $recordId,
                $field,
                $evidence->lastRetrievedAt()
            );
            $review->observe($value, clone $evidence, $evidence->lastRetrievedAt());
            $this->reviews->save($review);
        }
    }

    private function ingestEvidenceOnlyCandidate(
        MetadataRecordId $recordId,
        MetadataCandidate $candidate
    ): void {
        $evidence = MetadataFieldEvidence::fromCandidate($candidate);
        foreach ($this->candidateEvidenceOnlyValues($candidate) as [$field, $value]) {
            $review = $this->reviews->findForUpdate(
                $recordId,
                $field,
                $evidence->lastRetrievedAt()
            );
            $review->observe($value, clone $evidence, $evidence->lastRetrievedAt());
            $this->reviews->save($review);
        }
    }

    /** @return list<array{MetadataField, MetadataFieldValue}> */
    private function candidateValues(MetadataCandidate $candidate): array
    {
        $raw = [
            MetadataField::Title->value => $candidate->title(),
            MetadataField::Subtitle->value => $candidate->subtitle(),
            MetadataField::Languages->value => $candidate->languages(),
            MetadataField::Publishers->value => $candidate->publishers(),
            MetadataField::PublicationDate->value => $candidate->publicationDate(),
            MetadataField::PageCount->value => $candidate->pageCount(),
        ];
        return $this->presentCandidateValues($raw);
    }

    /** @return list<array{MetadataField, MetadataFieldValue}> */
    private function candidateEvidenceOnlyValues(
        MetadataCandidate $candidate
    ): array {
        return $this->presentCandidateValues([
            MetadataField::Contributors->value => $candidate->contributors(),
            MetadataField::Format->value => $candidate->format(),
        ]);
    }

    /**
     * @param array<string, string|int|list<string>|null> $raw
     * @return list<array{MetadataField, MetadataFieldValue}>
     */
    private function presentCandidateValues(array $raw): array
    {
        $values = [];
        foreach ($raw as $field => $value) {
            if ($value !== null && $value !== []) {
                $values[] = [
                    MetadataField::from($field),
                    new MetadataFieldValue($value),
                ];
            }
        }
        return $values;
    }

    private function candidateWasCorrected(MetadataCandidate $candidate): bool
    {
        $candidateValues = [
            UserObservedMetadataField::Isbn->value =>
                new MetadataFieldValue(
                    $candidate->returnedIsbn()->isbn13()->value()
                ),
        ];
        foreach ([
            ...$this->candidateValues($candidate),
            ...$this->candidateEvidenceOnlyValues($candidate),
        ] as [$field, $value]) {
            $candidateValues[$field->value] = $value;
        }

        foreach ($this->observedMetadata->values() as $field => $value) {
            if (
                !isset($candidateValues[$field])
                || !$candidateValues[$field]->equals($value)
            ) {
                return true;
            }
        }

        return false;
    }

    private function isEditionReviewField(MetadataField $field): bool
    {
        return match ($field) {
            MetadataField::Title,
            MetadataField::Subtitle,
            MetadataField::Languages,
            MetadataField::Publishers,
            MetadataField::PublicationDate,
            MetadataField::PageCount => true,
            MetadataField::Contributors,
            MetadataField::Format => false,
        };
    }
}
