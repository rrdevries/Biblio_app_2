<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class MigrationArtifact
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $kind,
        private string $sourceDigest,
        private array $payload
    ) {
    }

    public function kind(): string { return $this->kind; }
    public function sourceDigest(): string { return $this->sourceDigest; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function canonicalJson(): string { return DeterministicJson::encode($this->payload, true) . "\n"; }
    public function checksum(): string { return hash("sha256", $this->canonicalJson()); }
}
