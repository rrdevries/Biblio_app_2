<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;

final readonly class CurrentV1ClassificationMappingResult
{
    /**
     * @param array<string,LibraryCatalogSelection> $selectionsByBookId
     * @param list<MigrationSourceMappingFinding> $findings
     */
    public function __construct(
        private array $selectionsByBookId,
        private array $findings
    ) {
    }

    public function selection(string $sourceBookId): ?LibraryCatalogSelection
    {
        return $this->selectionsByBookId[$sourceBookId] ?? null;
    }

    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array { return $this->findings; }
}
