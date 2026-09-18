<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;

final readonly class CurrentV1ItemLocalMappingResult
{
    /**
     * @param array<string,CurrentV1ItemLocalMapping> $mappingsByCopyId
     * @param list<MigrationSourceMappingFinding> $findings
     * @param list<MigrationSourceRecord> $records
     */
    public function __construct(
        private array $mappingsByCopyId,
        private array $findings,
        private array $records = []
    ) {
    }

    public function forCopy(string $sourceCopyId): ?CurrentV1ItemLocalMapping
    {
        return $this->mappingsByCopyId[$sourceCopyId] ?? null;
    }

    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array { return $this->findings; }
    /** @return list<MigrationSourceRecord> */
    public function records(): array { return $this->records; }
}
