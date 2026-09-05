<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\{Series,SeriesPosition};

final readonly class CatalogQuerySeriesContext
{
    public function __construct(private Series $series, private SeriesPosition $position)
    {
    }

    public function series(): Series { return $this->series; }
    public function position(): SeriesPosition { return $this->position; }
}
