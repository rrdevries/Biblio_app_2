<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceRecord
{
    private string $payloadHash;

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $references
     */
    public function __construct(
        private string $sourceType,
        private string $sourceId,
        private array $payload,
        private array $references = [],
        private ?TypedMigrationPlan $typedPlan = null
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->sourceType) !== 1) {
            throw new ValidationException("Source type is invalid.");
        }
        if (
            trim($this->sourceId) === ""
            || mb_strlen($this->sourceId) > 191
            || preg_match('//u', $this->sourceId) !== 1
        ) {
            throw new ValidationException("Source ID is invalid.");
        }
        foreach ($this->references as $reference) {
            if (trim($reference) === "") {
                throw new ValidationException("Source reference is invalid.");
            }
        }
        $this->payloadHash = DeterministicJson::hash($this->payload);

        if (
            $this->typedPlan !== null
            && DeterministicJson::hash($this->typedPlan->canonicalPayload())
                !== $this->payloadHash
        ) {
            throw new ValidationException(
                "Typed migration plan does not match its canonical payload."
            );
        }
    }

    /** @param list<string> $references */
    public static function typed(
        string $sourceType,
        string $sourceId,
        TypedMigrationPlan $plan,
        array $references = []
    ): self {
        return new self(
            $sourceType,
            $sourceId,
            $plan->canonicalPayload(),
            $references,
            $plan
        );
    }

    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function typedPlan(): ?TypedMigrationPlan { return $this->typedPlan; }
    /** @return list<string> */
    public function references(): array { return $this->references; }

    /** @return array{source_type:string,source_id:string,payload_hash:string,references:list<string>} */
    public function identityArray(): array
    {
        $references = $this->references;
        sort($references, SORT_STRING);
        return [
            "source_type" => $this->sourceType,
            "source_id" => $this->sourceId,
            "payload_hash" => $this->payloadHash,
            "references" => $references,
        ];
    }
}
