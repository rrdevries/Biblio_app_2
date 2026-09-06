<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\LibraryLocation;

final readonly class AddBookExistingItem
{
    public function __construct(
        private ItemId $itemId,
        private ?InventoryNumber $inventoryNumber,
        private ?LibraryLocation $location
    ) {
    }

    public function itemId(): ItemId { return $this->itemId; }
    public function inventoryNumber(): ?InventoryNumber
    {
        return $this->inventoryNumber;
    }
    public function location(): ?LibraryLocation { return $this->location; }
}
