<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationLedgerObservation
{
    /** @param list<MigrationTargetMapping> $mappings */
    public function __construct(
        private string $id,
        private string $sourceFamily,
        private string $sourceSnapshot,
        private string $sourceType,
        private string $sourceId,
        private string $payloadHash,
        private string $processingStatus,
        private ?MigrationDisposition $disposition,
        private ?string $reasonCode,
        private bool $retryable,
        private array $mappings
    ) {
    }

    public function id(): string { return $this->id; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceSnapshot(): string { return $this->sourceSnapshot; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function processingStatus(): string { return $this->processingStatus; }
    public function disposition(): ?MigrationDisposition { return $this->disposition; }
    public function reasonCode(): ?string { return $this->reasonCode; }
    public function retryable(): bool { return $this->retryable; }
    /** @return list<MigrationTargetMapping> */
    public function mappings(): array { return $this->mappings; }

    public function identityKey(): string
    {
        return $this->sourceType . "\0" . $this->sourceId;
    }
}
