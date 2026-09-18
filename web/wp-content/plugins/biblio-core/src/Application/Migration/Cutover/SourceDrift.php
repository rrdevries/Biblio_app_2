<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

final readonly class SourceDrift
{
    public function __construct(
        private string $domain,
        private string $sourceType,
        private string $sourceId,
        private SourceDriftCategory $category,
        private string $reasonCode,
        private ?string $referencePayloadHash,
        private ?string $candidatePayloadHash
    ) {
    }

    public function category(): SourceDriftCategory { return $this->category; }
    public function domain(): string { return $this->domain; }

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
        ];
    }
}
