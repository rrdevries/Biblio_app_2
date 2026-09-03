<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\LibraryItemMetadataRepository;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryItemMetadataQueryService
{
    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private LibraryItemMetadataRepository $repository
    ) {
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, InventoryNumber|null>
     */
    public function inventoryNumbers(
        LibraryId $libraryId,
        array $itemIds
    ): array {
        $this->libraryContexts->get($libraryId);

        return $this->repository->inventoryNumbersForItems(
            $libraryId,
            $itemIds
        );
    }
}
