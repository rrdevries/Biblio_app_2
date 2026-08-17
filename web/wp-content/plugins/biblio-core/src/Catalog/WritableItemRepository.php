<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableItemRepository extends ItemRepository
{
    public function add(Item $item): void;
}
