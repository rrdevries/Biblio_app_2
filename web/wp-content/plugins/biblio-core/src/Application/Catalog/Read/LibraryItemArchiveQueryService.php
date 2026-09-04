<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\{Item,ItemArchivePeriod,ItemArchiveRepository,ItemId,ItemRepository};
use Biblio\Core\Library\LibraryId;

final readonly class LibraryItemArchiveQueryService
{
    public function __construct(private LibraryContextQueryService $contexts, private ItemRepository $items, private ItemArchiveRepository $archives) {}

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, Item|null>
     */
    public function items(LibraryId $libraryId, array $itemIds): array
    {
        $this->contexts->get($libraryId);
        return $this->items->findManyInLibrary($libraryId, $itemIds);
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, list<ItemArchivePeriod>>
     */
    public function periods(LibraryId $libraryId, array $itemIds): array
    {
        $this->contexts->get($libraryId);
        return $this->archives->periodsForItems($libraryId, $itemIds);
    }
}
