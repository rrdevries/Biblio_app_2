<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;

final readonly class PriorPreservedEvidence
{
    public function __construct(
        private string $runId,
        private string $observationId,
        private string $sourceSnapshot,
        private ?string $sourceVersion,
        private string $payloadHash,
        private string $payloadJson,
        private string $reasonCode,
        private string $preservationReason,
        private string $evidenceJson,
        private string $evidenceReference
    ) {
        if (
            trim($this->runId) === ""
            || preg_match('/^[a-f0-9]{64}$/D', $this->observationId) !== 1
            || trim($this->sourceSnapshot) === ""
            || preg_match('/^[a-f0-9]{64}$/D', $this->payloadHash) !== 1
            || trim($this->reasonCode) === ""
            || trim($this->preservationReason) === ""
            || trim($this->evidenceReference) === ""
        ) {
            throw new ValidationException("Prior preserved migration evidence is invalid.");
        }
        json_decode($this->payloadJson, true, 64, JSON_THROW_ON_ERROR);
        json_decode($this->evidenceJson, true, 64, JSON_THROW_ON_ERROR);
    }

    public function runId(): string { return $this->runId; }
    public function observationId(): string { return $this->observationId; }
    public function sourceSnapshot(): string { return $this->sourceSnapshot; }
    public function sourceVersion(): ?string { return $this->sourceVersion; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function payloadJson(): string { return $this->payloadJson; }
    public function reasonCode(): string { return $this->reasonCode; }
    public function preservationReason(): string { return $this->preservationReason; }
    public function evidenceJson(): string { return $this->evidenceJson; }
    public function evidenceReference(): string { return $this->evidenceReference; }
}
