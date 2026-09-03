<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableSeriesRepository extends SeriesRepository
{
    public function save(Series $series): void;
    public function addMembership(WorkSeriesMembership $membership): void;
}
