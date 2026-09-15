<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

final readonly class MigrationReconciliationReport
{
    /**
     * @param array<string, int> $dispositionCounts
     * @param array<string, mixed> $mappingCounts
     * @param array<string, bool> $acceptanceFlags
     * @param array<string, int> $reasonCodeSummaries
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private bool $accepted,
        private int $sourceObservationCount,
        private array $dispositionCounts,
        private array $mappingCounts,
        private int $uncommittedCount,
        private int $unexplainedDropCount,
        private int $unresolvedDependencyCount,
        private int $brokenTargetCount,
        private array $acceptanceFlags,
        private array $reasonCodeSummaries,
        private array $payload
    ) {
    }

    public function accepted(): bool { return $this->accepted; }
    public function sourceObservationCount(): int
    {
        return $this->sourceObservationCount;
    }
    /** @return array<string, int> */
    public function dispositionCounts(): array
    {
        return $this->dispositionCounts;
    }
    /** @return array<string, mixed> */
    public function mappingCounts(): array
    {
        return $this->mappingCounts;
    }
    public function uncommittedCount(): int
    {
        return $this->uncommittedCount;
    }
    public function unexplainedDropCount(): int
    {
        return $this->unexplainedDropCount;
    }
    public function unresolvedDependencyCount(): int
    {
        return $this->unresolvedDependencyCount;
    }
    public function brokenTargetCount(): int
    {
        return $this->brokenTargetCount;
    }
    /** @return array<string, bool> */
    public function acceptanceFlags(): array
    {
        return $this->acceptanceFlags;
    }
    /** @return array<string, int> */
    public function reasonCodeSummaries(): array
    {
        return $this->reasonCodeSummaries;
    }
    /** @return array<string, mixed> */
    public function toArray(): array { return $this->payload; }
}
