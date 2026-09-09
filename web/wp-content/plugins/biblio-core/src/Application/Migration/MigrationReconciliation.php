<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationReconciliation
{
    /** @param array<string, int> $dispositions */
    public function __construct(
        private string $runId,
        private int $sourceTotal,
        private array $dispositions,
        private int $createdTargets,
        private int $reusedTargets,
        private int $mappingEdges,
        private int $uncommitted
    ) {
    }

    public function runId(): string { return $this->runId; }
    public function sourceTotal(): int { return $this->sourceTotal; }
    /** @return array<string, int> */
    public function dispositions(): array { return $this->dispositions; }
    public function createdTargets(): int { return $this->createdTargets; }
    public function reusedTargets(): int { return $this->reusedTargets; }
    public function mappingEdges(): int { return $this->mappingEdges; }
    public function uncommitted(): int { return $this->uncommitted; }
    public function isExact(): bool
    {
        return array_sum($this->dispositions) === $this->sourceTotal
            && $this->uncommitted === 0;
    }
}
