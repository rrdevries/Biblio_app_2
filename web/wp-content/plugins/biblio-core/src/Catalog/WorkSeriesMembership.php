<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class WorkSeriesMembership
{
    public function __construct(
        private WorkId $workId,
        private SeriesId $seriesId,
        private SeriesPosition $position
    ) {
    }

    public function workId(): WorkId { return $this->workId; }
    public function seriesId(): SeriesId { return $this->seriesId; }
    public function position(): SeriesPosition { return $this->position; }
}
