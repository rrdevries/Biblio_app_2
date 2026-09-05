<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Application\Library\LibraryContextView;

final readonly class CatalogQueryPage
{
    /** @param list<CatalogQueryItem> $items */
    public function __construct(
        private LibraryContextView $library,
        private array $items,
        private ?CatalogQueryCursor $nextCursor
    ) {
    }

    public function library(): LibraryContextView { return $this->library; }
    /** @return list<CatalogQueryItem> */ public function items(): array { return $this->items; }
    public function nextCursor(): ?CatalogQueryCursor { return $this->nextCursor; }
}
