<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\MigrationEvidence;
use Biblio\Core\Exception\ValidationException;

/** Privacy-safe preservation metadata carried by a typed Item plan. */
final readonly class CatalogItemPreservationPlan
{
    /** @var array<string,bool|int> */
    private array $evidence;

    /** @param array<string,mixed> $evidence */
    public function __construct(
        private string $reason,
        private string $sourceEvidenceHash,
        private string $sourceEvidenceReference,
        array $evidence
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reason) !== 1) {
            throw new ValidationException("Item preservation reason is invalid.");
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->sourceEvidenceHash) !== 1) {
            throw new ValidationException("Item source evidence hash is invalid.");
        }
        if (
            trim($this->sourceEvidenceReference) === ""
            || mb_strlen($this->sourceEvidenceReference) > 1024
            || preg_match('//u', $this->sourceEvidenceReference) !== 1
        ) {
            throw new ValidationException("Item source evidence reference is invalid.");
        }
        /** @var array<string,bool|int> $validated */
        $validated = [];
        foreach ($evidence as $key => $value) {
            if (
                preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || !is_bool($value) && !is_int($value)
                || is_int($value) && $value < 0
            ) {
                throw new ValidationException("Item preservation evidence is invalid.");
            }
            $validated[$key] = $value;
        }
        ksort($validated, SORT_STRING);
        MigrationEvidence::canonicalJson($validated);
        $this->evidence = $validated;
    }

    public function reason(): string { return $this->reason; }
    public function sourceEvidenceReference(): string
    {
        return $this->sourceEvidenceReference;
    }

    /** @return array<string,mixed> */
    public function canonicalPayload(): array
    {
        return [
            "reason" => $this->reason,
            "source_evidence_hash" => $this->sourceEvidenceHash,
            "source_evidence_reference" => $this->sourceEvidenceReference,
            "evidence" => $this->evidence,
        ];
    }

    /** @return array<string,bool|int|string> */
    public function outcomeEvidence(): array
    {
        return [
            "source_evidence_sha256" => $this->sourceEvidenceHash,
            ...$this->evidence,
        ];
    }
}
