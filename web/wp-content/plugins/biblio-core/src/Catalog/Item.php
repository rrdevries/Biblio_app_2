<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

final readonly class Item
{
    private ?InventoryNumber $inventoryNumber;

    public function __construct(
        private ItemId $id,
        private LibraryId $libraryId,
        private EditionId $editionId,
        private ItemStatus $status,
        ?InventoryNumber $inventoryNumber = null
    ) {
        $this->inventoryNumber = $inventoryNumber;
    }

    public static function active(
        ItemId $id,
        LibraryId $libraryId,
        EditionId $editionId,
        ?InventoryNumber $inventoryNumber = null
    ): self {
        return new self(
            $id,
            $libraryId,
            $editionId,
            ItemStatus::Active,
            $inventoryNumber
        );
    }

    public function id(): ItemId
    {
        return $this->id;
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function editionId(): EditionId
    {
        return $this->editionId;
    }

    public function status(): ItemStatus
    {
        return $this->status;
    }

    public function inventoryNumber(): ?InventoryNumber
    {
        return $this->inventoryNumber;
    }
}
