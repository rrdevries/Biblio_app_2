<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;

final readonly class CurrentV1ItemLocalMappingResult
{
    /**
     * @param array<string,CurrentV1ItemLocalMapping> $mappingsByCopyId
     * @param list<MigrationSourceMappingFinding> $findings
     */
    public function __construct(
        private array $mappingsByCopyId,
        private array $findings
    ) {
    }

    public function forCopy(string $sourceCopyId): ?CurrentV1ItemLocalMapping
    {
        return $this->mappingsByCopyId[$sourceCopyId] ?? null;
    }

    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array { return $this->findings; }
}
