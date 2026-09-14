<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Library\LibraryId;

final readonly class ItemLocalDetails
{
    public function __construct(
        private LibraryId $libraryId,
        private ItemId $itemId,
        private ItemLocalDetailsState $state,
        private ItemLocalDetailsVersion $version
    ) {
    }

    public static function create(
        LibraryId $libraryId,
        ItemId $itemId,
        ItemLocalDetailsState $state
    ): self {
        return new self($libraryId, $itemId, $state, new ItemLocalDetailsVersion(1));
    }

    public function replace(ItemLocalDetailsState $state): self
    {
        return new self($this->libraryId, $this->itemId, $state, $this->version->next());
    }

    public function libraryId(): LibraryId { return $this->libraryId; }
    public function itemId(): ItemId { return $this->itemId; }
    public function state(): ItemLocalDetailsState { return $this->state; }
    public function version(): ItemLocalDetailsVersion { return $this->version; }
}
