<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationLedgerMappingAnomaly
{
    public function __construct(
        private string $observationId,
        private string $targetType,
        private string $targetId,
        private MappingDisposition $disposition,
        private ?string $reasonCode
    ) {
    }

    public function observationId(): string { return $this->observationId; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function disposition(): MappingDisposition { return $this->disposition; }
    public function reasonCode(): ?string { return $this->reasonCode; }
}
