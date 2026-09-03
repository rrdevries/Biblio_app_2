<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\AlternateWorkTitle;
use Biblio\Core\Catalog\BibliographicMetadataRepository;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Isbn;
use Biblio\Core\Catalog\WorkContainment;
use Biblio\Core\Catalog\WorkId;

final readonly class BibliographicMetadataQueryService
{
    public function __construct(
        private BibliographicMetadataRepository $repository
    ) {
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<AlternateWorkTitle>>
     */
    public function alternateTitles(array $workIds): array
    {
        return $this->repository->alternateTitlesForWorks($workIds);
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<Edition>>
     */
    public function editions(array $workIds): array
    {
        return $this->repository->editionsForWorks($workIds);
    }

    /**
     * @param list<Isbn> $isbns
     * @return array<string, list<Edition>>
     */
    public function editionsByIsbn(array $isbns): array
    {
        return $this->repository->editionsForIsbns($isbns);
    }

    /**
     * @param list<WorkId> $parentWorkIds
     * @return array<string, list<WorkContainment>>
     */
    public function containedWorks(array $parentWorkIds): array
    {
        return $this->repository->containedWorksForParents($parentWorkIds);
    }

    /**
     * @param list<WorkId> $containedWorkIds
     * @return array<string, list<WorkContainment>>
     */
    public function parentWorks(array $containedWorkIds): array
    {
        return $this->repository->parentWorksForContained($containedWorkIds);
    }
}
