<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\{EditionId,ItemId,ItemStatus,WorkId};

final readonly class CatalogQueryItemRecord
{
    public function __construct(
        private ItemId $itemId,
        private WorkId $workId,
        private EditionId $editionId,
        private string $title,
        private ItemStatus $itemStatus,
        private ?string $inventoryNumber,
        private ?string $containedMatchTitle
    ) {
    }

    public function itemId(): ItemId { return $this->itemId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function title(): string { return $this->title; }
    public function itemStatus(): ItemStatus { return $this->itemStatus; }
    public function inventoryNumber(): ?string { return $this->inventoryNumber; }
    public function containedMatchTitle(): ?string { return $this->containedMatchTitle; }
}
