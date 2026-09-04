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
        ?InventoryNumber $inventoryNumber = null,
        private ?LocationId $locationId = null,
        private ItemVersion $version = new ItemVersion(1)
    ) {
        $this->inventoryNumber = $inventoryNumber;
    }

    public static function active(
        ItemId $id,
        LibraryId $libraryId,
        EditionId $editionId,
        ?InventoryNumber $inventoryNumber = null,
        ?LocationId $locationId = null
    ): self {
        return new self(
            $id,
            $libraryId,
            $editionId,
            ItemStatus::Active,
            $inventoryNumber,
            $locationId
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

    public function locationId(): ?LocationId
    {
        return $this->locationId;
    }

    public function version(): ItemVersion
    {
        return $this->version;
    }

    public function archive(): self
    {
        if ($this->status !== ItemStatus::Active) {
            throw new ItemArchiveTransitionUnavailable();
        }

        return new self(
            $this->id,
            $this->libraryId,
            $this->editionId,
            ItemStatus::Archived,
            $this->inventoryNumber,
            $this->locationId,
            $this->version->next()
        );
    }

    public function restore(): self
    {
        if ($this->status !== ItemStatus::Archived) {
            throw new ItemArchiveTransitionUnavailable();
        }

        return new self(
            $this->id,
            $this->libraryId,
            $this->editionId,
            ItemStatus::Active,
            $this->inventoryNumber,
            $this->locationId,
            $this->version->next()
        );
    }
}
