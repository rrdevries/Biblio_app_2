<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationTraceEntry
{
    public function __construct(
        private string $runId,
        private string $sourceFamily,
        private string $sourceType,
        private string $sourceId,
        private string $sourceSnapshot,
        private string $payloadHash,
        private string $targetType,
        private string $targetId,
        private MappingDisposition $mappingDisposition
    ) {
    }

    public function runId(): string { return $this->runId; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function sourceSnapshot(): string { return $this->sourceSnapshot; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function mappingDisposition(): MappingDisposition
    {
        return $this->mappingDisposition;
    }
}
