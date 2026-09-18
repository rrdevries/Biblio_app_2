<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

final readonly class FinalSourceCompatibilityReport
{
    /** @param list<SourceDrift> $drift */
    public function __construct(
        private FinalSourceSnapshot $reference,
        private FinalSourceSnapshot $candidate,
        private array $drift
    ) {
    }

    /** @return list<SourceDrift> */
    public function drift(): array { return $this->drift; }

    public function approvalState(): FinalSourceApprovalState
    {
        foreach ($this->drift as $item) {
            if ($item->category()->requiresContractReview()) {
                return FinalSourceApprovalState::ReviewRequired;
            }
        }
        return FinalSourceApprovalState::MechanicallyCompatible;
    }

    public function compatibility(): string
    {
        return $this->approvalState() === FinalSourceApprovalState::ReviewRequired
            ? "CONTRACT_REVIEW_REQUIRED"
            : "MAPPING_CONTRACT_COMPATIBLE";
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $categories = array_fill_keys(array_map(
            static fn (SourceDriftCategory $category): string => $category->value,
            SourceDriftCategory::cases()
        ), 0);
        $domains = [];
        foreach ($this->drift as $item) {
            $categories[$item->category()->value]++;
            $domains[$item->domain()] ??= array_fill_keys(array_keys($categories), 0);
            $domains[$item->domain()][$item->category()->value]++;
        }
        ksort($domains, SORT_STRING);

        return [
            "reference_package" => $this->reference->package()->toArray(),
            "candidate_package" => $this->candidate->package()->toArray(),
            "compatibility" => $this->compatibility(),
            "approval_state" => $this->approvalState()->value,
            "category_counts" => $categories,
            "domain_category_counts" => $domains,
            "circulation_profile" => $this->candidate->circulationProfile(),
            "circulation_cutover_gate" => ($this->candidate->circulationProfile()["open_total"] ?? 0) > 0
                ? "CIRCULATION_CUTOVER_REVIEW_REQUIRED"
                : "NO_OPEN_CIRCULATION_GATE",
            "quarantine_candidates" => $this->candidate->quarantineCandidates(),
            "drift" => array_map(
                static fn (SourceDrift $item): array => $item->toArray(),
                $this->drift
            ),
        ];
    }

    public function digest(): string { return DeterministicJson::hash($this->toArray()); }
}
