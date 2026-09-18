<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Exception\ValidationException;

final readonly class FinalSourceSnapshot
{
    /** @var list<FinalSourceObservation> */
    private array $observations;
    /** @var array<string,int> */
    private array $sourceTypeCounts;
    /** @var array<string,mixed> */
    private array $circulationProfile;
    /** @var list<array{source_type:string,source_id:string,reason:string,evidence_hash:string,no_target:bool}> */
    private array $quarantineCandidates;

    /**
     * @param list<FinalSourceObservation> $observations
     * @param array<string,int> $sourceTypeCounts
     * @param array<string,mixed> $circulationProfile
     * @param list<array{source_type:string,source_id:string,reason:string,evidence_hash:string,no_target:bool}> $quarantineCandidates
     */
    public function __construct(
        private FinalSourcePackageIdentity $package,
        array $observations,
        array $sourceTypeCounts,
        array $circulationProfile,
        array $quarantineCandidates
    ) {
        $indexed = [];
        foreach ($observations as $observation) {
            if (isset($indexed[$observation->key()])) {
                throw new ValidationException("Final source snapshot observations are invalid.");
            }
            $indexed[$observation->key()] = $observation;
        }
        ksort($indexed, SORT_STRING);
        foreach ($sourceTypeCounts as $sourceType => $count) {
            if (
                preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sourceType) !== 1
                || $count < 0
            ) {
                throw new ValidationException("Final source type inventory is invalid.");
            }
        }
        foreach ($quarantineCandidates as $candidate) {
            $keys = array_keys($candidate);
            sort($keys, SORT_STRING);
            if (
                $keys !== ["evidence_hash", "no_target", "reason", "source_id", "source_type"]
                || trim($candidate["source_type"]) === ""
                || trim($candidate["source_id"]) === ""
                || trim($candidate["reason"]) === ""
                || preg_match('/^[a-f0-9]{64}$/D', $candidate["evidence_hash"]) !== 1
                || $candidate["no_target"] !== true
            ) {
                throw new ValidationException("Final source quarantine candidate is invalid.");
            }
        }
        ksort($sourceTypeCounts, SORT_STRING);
        usort($quarantineCandidates, static fn (array $a, array $b): int =>
            [$a["source_type"], $a["source_id"]] <=> [$b["source_type"], $b["source_id"]]);
        $this->observations = array_values($indexed);
        $this->sourceTypeCounts = $sourceTypeCounts;
        $this->circulationProfile = $circulationProfile;
        $this->quarantineCandidates = $quarantineCandidates;
    }

    public function package(): FinalSourcePackageIdentity { return $this->package; }
    /** @return list<FinalSourceObservation> */
    public function observations(): array { return $this->observations; }
    /** @return array<string,int> */
    public function sourceTypeCounts(): array { return $this->sourceTypeCounts; }
    /** @return array<string,mixed> */
    public function circulationProfile(): array { return $this->circulationProfile; }
    /** @return list<array{source_type:string,source_id:string,reason:string,evidence_hash:string,no_target:bool}> */
    public function quarantineCandidates(): array { return $this->quarantineCandidates; }

    public function digest(): string
    {
        return DeterministicJson::hash($this->semanticArray());
    }

    /** @return array<string,mixed> */
    public function semanticArray(): array
    {
        return [
            "package" => $this->package->toArray(),
            "source_type_counts" => $this->sourceTypeCounts,
            "observations" => array_map(
                static fn (FinalSourceObservation $observation): array => $observation->toArray(),
                $this->observations
            ),
            "circulation_profile" => $this->circulationProfile,
            "quarantine_candidates" => $this->quarantineCandidates,
        ];
    }
}
