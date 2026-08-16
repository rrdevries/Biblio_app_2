<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

final readonly class Item
{
    public function __construct(
        private ItemId $id,
        private LibraryId $libraryId,
        private EditionId $editionId,
        private ItemStatus $status
    ) {
    }

    public static function active(
        ItemId $id,
        LibraryId $libraryId,
        EditionId $editionId
    ): self {
        return new self($id, $libraryId, $editionId, ItemStatus::Active);
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
}
