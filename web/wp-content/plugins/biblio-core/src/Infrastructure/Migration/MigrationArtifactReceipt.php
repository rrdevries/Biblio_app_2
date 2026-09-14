<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

final readonly class MigrationArtifactReceipt
{
    public function __construct(
        private string $artifactPath,
        private string $checksumPath,
        private string $checksum
    ) {
    }

    public function artifactPath(): string { return $this->artifactPath; }
    public function checksumPath(): string { return $this->checksumPath; }
    public function checksum(): string { return $this->checksum; }
}
