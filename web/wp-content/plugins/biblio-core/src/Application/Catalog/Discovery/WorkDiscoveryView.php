<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

use Biblio\Core\Catalog\{Author,WorkId,WorkTitleStatus};

final readonly class WorkDiscoveryView
{
    /**
     * @param list<Author> $authors
     * @param list<WorkDiscoverySeriesView> $series
     */
    public function __construct(
        private WorkId $workId,
        private string $title,
        private WorkTitleStatus $titleStatus,
        private array $authors,
        private array $series
    ) {
    }

    public function workId(): WorkId { return $this->workId; }
    public function title(): string { return $this->title; }
    public function titleStatus(): WorkTitleStatus { return $this->titleStatus; }
    /** @return list<Author> */ public function authors(): array { return $this->authors; }
    /** @return list<WorkDiscoverySeriesView> */ public function series(): array { return $this->series; }
}
