<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Catalog\Item;

final readonly class AccessibleLibraryItem
{
    public function __construct(
        private Item $item,
        private bool $usableAsDirectSource
    ) {
    }

    public function item(): Item
    {
        return $this->item;
    }

    public function canUseAsDirectSource(): bool
    {
        return $this->usableAsDirectSource;
    }
}
