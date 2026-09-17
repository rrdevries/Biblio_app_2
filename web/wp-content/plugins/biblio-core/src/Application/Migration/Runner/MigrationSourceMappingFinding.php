<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceMappingFinding
{
    /**
     * @param list<array{source_type:string,source_id:string}> $plannedIdentities
     */
    public function __construct(
        private string $sourceType,
        private string $sourceId,
        private MigrationDisposition $disposition,
        private string $reasonCode,
        private array $plannedIdentities = [],
        private int $occurrenceCount = 1,
        private ?string $evidenceHash = null
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->sourceType) !== 1) {
            throw new ValidationException("Mapping finding source type is invalid.");
        }
        if (trim($this->sourceId) === "" || mb_strlen($this->sourceId) > 191) {
            throw new ValidationException("Mapping finding source ID is invalid.");
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reasonCode) !== 1) {
            throw new ValidationException("Mapping finding reason is invalid.");
        }
        if ($this->occurrenceCount < 1) {
            throw new ValidationException("Mapping finding occurrence count is invalid.");
        }
        if (
            $this->evidenceHash !== null
            && preg_match('/^[a-f0-9]{64}$/D', $this->evidenceHash) !== 1
        ) {
            throw new ValidationException("Mapping finding evidence hash is invalid.");
        }
        foreach ($this->plannedIdentities as $identity) {
            if (
                preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $identity["source_type"]) !== 1
                || trim($identity["source_id"]) === ""
                || mb_strlen($identity["source_id"]) > 191
            ) {
                throw new ValidationException("Mapping finding plan identity is invalid.");
            }
        }
    }

    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function disposition(): MigrationDisposition { return $this->disposition; }
    public function reasonCode(): string { return $this->reasonCode; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $identities = $this->plannedIdentities;
        usort($identities, static fn (array $a, array $b): int =>
            [$a["source_type"], $a["source_id"]]
                <=> [$b["source_type"], $b["source_id"]]);

        $result = [
            "source_type" => $this->sourceType,
            "source_id" => $this->sourceId,
            "disposition" => $this->disposition->value,
            "reason_code" => $this->reasonCode,
            "occurrence_count" => $this->occurrenceCount,
            "planned_identities" => $identities,
        ];
        if ($this->evidenceHash !== null) {
            $result["evidence_hash"] = $this->evidenceHash;
        }
        return $result;
    }
}
