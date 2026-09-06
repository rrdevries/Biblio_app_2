<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Identity\UserId;

final readonly class MetadataFieldReviewService
{
    public function __construct(
        private MetadataFieldReviewRepository $reviews,
        private TransactionManager $transactions,
        private MetadataClock $clock
    ) {
    }

    /** @return array<string, MetadataFieldReview> */
    public function ingestCandidate(
        MetadataRecordId $recordId,
        MetadataCandidate $candidate
    ): array {
        $values = $this->candidateValues($candidate);
        $evidence = MetadataFieldEvidence::fromCandidate($candidate);

        /** @var array<string, MetadataFieldReview> */
        return $this->transactions->run(function () use (
            $recordId,
            $values,
            $evidence
        ): array {
            $result = [];
            foreach ($values as $fieldValue) {
                [$field, $value] = $fieldValue;
                $review = $this->reviews->findForUpdate(
                    $recordId,
                    $field,
                    $evidence->lastRetrievedAt()
                );
                $review->observe($value, clone $evidence, $evidence->lastRetrievedAt());
                $this->reviews->save($review);
                $result[$field->value] = $review;
            }
            return $result;
        });
    }

    public function recordUnconfirmedValue(
        MetadataRecordId $recordId,
        MetadataField $field,
        MetadataFieldValue $value
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ) use ($value): void {
            $review->recordUnconfirmedValue($value, $at);
        });
    }

    public function correctManually(
        MetadataRecordId $recordId,
        MetadataField $field,
        MetadataFieldValue $value,
        UserId $actor
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ) use ($value, $actor): void {
            $review->correctManually($value, $actor, $at);
        });
    }

    public function confirm(
        MetadataRecordId $recordId,
        MetadataField $field,
        string $valueHash,
        UserId $actor
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ) use ($valueHash, $actor): void {
            $review->confirm($valueHash, $actor, $at);
        });
    }

    public function reject(
        MetadataRecordId $recordId,
        MetadataField $field,
        string $valueHash,
        UserId $actor
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ) use ($valueHash, $actor): void {
            $review->reject($valueHash, $actor, $at);
        });
    }

    public function markIntentionallyBlank(
        MetadataRecordId $recordId,
        MetadataField $field,
        UserId $actor
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ) use ($actor): void {
            $review->markIntentionallyBlank($actor, $at);
        });
    }

    public function allowProposalsAgain(
        MetadataRecordId $recordId,
        MetadataField $field
    ): MetadataFieldReview {
        return $this->mutate($recordId, $field, static function (
            MetadataFieldReview $review,
            \DateTimeImmutable $at
        ): void {
            $review->allowProposalsAgain($at);
        });
    }

    private function mutate(
        MetadataRecordId $recordId,
        MetadataField $field,
        callable $mutation
    ): MetadataFieldReview {
        return $this->transactions->run(function () use (
            $recordId,
            $field,
            $mutation
        ): MetadataFieldReview {
            $at = $this->clock->now();
            $review = $this->reviews->findForUpdate($recordId, $field, $at);
            $mutation($review, $at);
            $this->reviews->save($review);
            return $review;
        });
    }

    /** @return list<array{MetadataField, MetadataFieldValue}> */
    private function candidateValues(MetadataCandidate $candidate): array
    {
        $values = [];
        $this->append($values, MetadataField::Title, $candidate->title());
        $this->append($values, MetadataField::Subtitle, $candidate->subtitle());
        $this->append($values, MetadataField::Contributors, $candidate->contributors());
        $this->append($values, MetadataField::Languages, $candidate->languages());
        $this->append($values, MetadataField::Publishers, $candidate->publishers());
        $this->append($values, MetadataField::PublicationDate, $candidate->publicationDate());
        $this->append($values, MetadataField::PageCount, $candidate->pageCount());
        $this->append($values, MetadataField::Format, $candidate->format());
        return $values;
    }

    /**
     * @param list<array{MetadataField, MetadataFieldValue}> $values
     * @param string|int|list<string>|null $value
     */
    private function append(
        array &$values,
        MetadataField $field,
        string|int|array|null $value
    ): void {
        if ($value !== null && $value !== []) {
            $values[] = [$field, new MetadataFieldValue($value)];
        }
    }
}
