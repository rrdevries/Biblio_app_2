<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\{Author,EditionId,ItemId,ItemStatus,LibraryLocation,WorkId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;

final readonly class CatalogQueryItem
{
    /**
     * @param list<Author> $authors
     * @param list<CatalogQuerySeriesContext> $series
     * @param list<CollectionId> $collectionIds
     */
    public function __construct(
        private ItemId $itemId,
        private WorkId $workId,
        private EditionId $editionId,
        private string $title,
        private ItemStatus $itemStatus,
        private ?string $inventoryNumber,
        private array $authors,
        private array $series,
        private ?LibraryLocation $location,
        private ?LibraryCatalogSelection $classification,
        private array $collectionIds,
        private PersonalWorkReadingStatus $readingStatus,
        private ?string $containedMatchTitle
    ) {
    }

    public function itemId(): ItemId { return $this->itemId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function title(): string { return $this->title; }
    public function itemStatus(): ItemStatus { return $this->itemStatus; }
    public function inventoryNumber(): ?string { return $this->inventoryNumber; }
    /** @return list<Author> */ public function authors(): array { return $this->authors; }
    /** @return list<CatalogQuerySeriesContext> */ public function series(): array { return $this->series; }
    public function location(): ?LibraryLocation { return $this->location; }
    public function classification(): ?LibraryCatalogSelection { return $this->classification; }
    /** @return list<CollectionId> */ public function collectionIds(): array { return $this->collectionIds; }
    public function readingStatus(): PersonalWorkReadingStatus { return $this->readingStatus; }
    public function containedMatchTitle(): ?string { return $this->containedMatchTitle; }
}
