<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Library\LibraryContextView;

final readonly class CatalogOverviewView
{
    /** @param list<CatalogItemCardView> $items */
    public function __construct(
        private LibraryContextView $library,
        private array $items,
        private ?CatalogOverviewCursor $nextCursor
    ) {
    }

    public function library(): LibraryContextView { return $this->library; }

    /** @return list<CatalogItemCardView> */
    public function items(): array { return $this->items; }
    public function nextCursor(): ?CatalogOverviewCursor { return $this->nextCursor; }
}
