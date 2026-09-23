<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Exception\ValidationException;

final readonly class SourceDrift
{
    public function __construct(
        private string $domain,
        private string $sourceType,
        private string $sourceId,
        private SourceDriftCategory $category,
        private string $reasonCode,
        private ?string $referencePayloadHash,
        private ?string $candidatePayloadHash,
        private ReviewedAuthorShapeDisposition|ReviewedClassificationQueueDisposition|ReviewedReadingRoundCompletionDisposition|null $reviewedDisposition = null
    ) {
        if ($this->reviewedDisposition !== null && !$this->reviewedDisposition->appliesTo(
            $domain, $sourceType, $sourceId, $category, $reasonCode, $referencePayloadHash, $candidatePayloadHash
        )) {
            throw new ValidationException("Reviewed disposition does not match drift evidence.");
        }
    }

    public function category(): SourceDriftCategory { return $this->category; }
    public function domain(): string { return $this->domain; }
    public function reviewedDisposition(): ReviewedAuthorShapeDisposition|ReviewedClassificationQueueDisposition|ReviewedReadingRoundCompletionDisposition|null
    {
        return $this->reviewedDisposition;
    }
    public function effectiveCategory(): SourceDriftCategory
    {
        return $this->reviewedDisposition === null ? $this->category : SourceDriftCategory::C;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "domain" => $this->domain,
            "source_type" => $this->sourceType,
            "source_id" => $this->sourceId,
            "category" => $this->category->value,
            "reason_code" => $this->reasonCode,
            "reference_payload_hash" => $this->referencePayloadHash,
            "candidate_payload_hash" => $this->candidatePayloadHash,
            "contract_review_required" => $this->category->requiresContractReview(),
            "effective_category" => $this->effectiveCategory()->value,
            "effective_contract_review_required" => $this->effectiveCategory()->requiresContractReview(),
            "reviewed_disposition" => $this->reviewedDisposition?->toArray(),
        ];
    }
}
