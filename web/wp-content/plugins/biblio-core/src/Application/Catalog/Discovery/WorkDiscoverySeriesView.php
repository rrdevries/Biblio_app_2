<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

use Biblio\Core\Catalog\{Series,SeriesPosition};

final readonly class WorkDiscoverySeriesView
{
    public function __construct(
        private Series $series,
        private SeriesPosition $position
    ) {
    }

    public function series(): Series { return $this->series; }
    public function position(): SeriesPosition { return $this->position; }
}
