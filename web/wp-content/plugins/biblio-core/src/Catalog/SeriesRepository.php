<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface SeriesRepository
{
    public function find(SeriesId $seriesId): ?Series;

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, Series>
     */
    public function findMany(array $seriesIds): array;

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<WorkSeriesMembership>>
     */
    public function membershipsForWorks(array $workIds): array;

    /**
     * @param list<SeriesId> $seriesIds
     * @return array<string, list<WorkSeriesMembership>>
     */
    public function membershipsForSeries(array $seriesIds): array;
}
