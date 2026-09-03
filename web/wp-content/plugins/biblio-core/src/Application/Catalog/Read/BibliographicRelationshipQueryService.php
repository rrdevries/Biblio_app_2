<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\{AuthorId,AuthorRepository,SeriesId,SeriesRepository,WorkId};

final readonly class BibliographicRelationshipQueryService
{
    public function __construct(
        private AuthorRepository $authors,
        private SeriesRepository $series
    ) {
    }

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, \Biblio\Core\Catalog\Author>
     */
    public function authors(array $authorIds): array
    {
        return $this->authors->findMany($authorIds);
    }

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, \Biblio\Core\Catalog\Series>
     */
    public function series(array $seriesIds): array
    {
        return $this->series->findMany($seriesIds);
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<\Biblio\Core\Catalog\WorkContributor>>
     */
    public function contributorsForWorks(array $workIds): array
    {
        return $this->authors->contributorsForWorks($workIds);
    }

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, list<WorkId>>
     */
    public function workIdsForAuthors(array $authorIds): array
    {
        return $this->authors->workIdsForAuthors($authorIds);
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<\Biblio\Core\Catalog\WorkSeriesMembership>>
     */
    public function seriesForWorks(array $workIds): array
    {
        return $this->series->membershipsForWorks($workIds);
    }

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, list<\Biblio\Core\Catalog\WorkSeriesMembership>>
     */
    public function worksForSeries(array $seriesIds): array
    {
        return $this->series->membershipsForSeries($seriesIds);
    }
}
