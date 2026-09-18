<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Exception\ValidationException;

final readonly class FinalPopulationContractBundle
{
    public const SCHEMA_VERSION = "final-population-contract-v1";

    private string $bundleDigest;

    public function __construct(
        private FinalSourceSnapshot $snapshot,
        private FinalSourceCompatibilityReport $report,
        private MapperContractInventory $inventory,
        private FinalSourceApprovalState $approvalState
    ) {
        if ($this->approvalState === FinalSourceApprovalState::ApprovedForRehearsal) {
            throw new ValidationException(
                "PREP-01A cannot create an approved-for-rehearsal bundle."
            );
        }
        if ($this->approvalState !== $this->report->approvalState()) {
            throw new ValidationException("Final population bundle approval state is inconsistent.");
        }
        $this->bundleDigest = DeterministicJson::hash($this->semanticArray());
    }

    public static function fromReport(
        FinalSourceSnapshot $snapshot,
        FinalSourceCompatibilityReport $report,
        ?MapperContractInventory $inventory = null
    ): self {
        return new self(
            $snapshot,
            $report,
            $inventory ?? new MapperContractInventory(),
            $report->approvalState()
        );
    }

    public function digest(): string { return $this->bundleDigest; }
    public function approvalState(): FinalSourceApprovalState { return $this->approvalState; }

    /**
     * Closed mapper seam. A mapper may consume only its typed inventory row
     * plus the exact final package and bundle digests; arbitrary metadata is
     * deliberately unavailable.
     *
     * @return array<string,mixed>
     */
    public function mapperContract(string $domain): array
    {
        foreach ($this->inventory->entries() as $entry) {
            if (($entry["domain"] ?? null) === $domain) {
                return [
                    "domain" => $domain,
                    "contract" => $entry,
                    "package" => $this->snapshot->package()->toArray(),
                    "snapshot_digest" => $this->snapshot->digest(),
                    "bundle_digest" => $this->bundleDigest,
                    "approval_state" => $this->approvalState->value,
                ];
            }
        }
        throw new ValidationException("Unknown final population mapper contract domain.");
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge($this->semanticArray(), ["bundle_sha256" => $this->bundleDigest]);
    }

    /** @return array<string,mixed> */
    private function semanticArray(): array
    {
        $observations = array_map(
            static fn (FinalSourceObservation $observation): array => $observation->toArray(),
            $this->snapshot->observations()
        );
        return [
            "schema_version" => self::SCHEMA_VERSION,
            "package" => $this->snapshot->package()->toArray(),
            "snapshot_digest" => $this->snapshot->digest(),
            "mapper_contract_inventory" => $this->inventory->entries(),
            "mapper_contract_inventory_digest" => $this->inventory->digest(),
            "source_type_counts" => $this->snapshot->sourceTypeCounts(),
            "population_evidence" => $observations,
            "quarantine_candidates" => $this->snapshot->quarantineCandidates(),
            "circulation_profile" => $this->snapshot->circulationProfile(),
            "compatibility_report_digest" => $this->report->digest(),
            "approval_state" => $this->approvalState->value,
        ];
    }
}
