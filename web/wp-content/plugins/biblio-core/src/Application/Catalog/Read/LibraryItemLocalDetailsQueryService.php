<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetailsRepository;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryItemLocalDetailsQueryService
{
    private const int MAX_BATCH = 100;

    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private ItemRepository $items,
        private ItemLocalDetailsRepository $details
    ) {
    }

    public function details(LibraryId $libraryId, ItemId $itemId): ItemLocalDetailsView
    {
        $this->libraryContexts->get($libraryId);
        if ($this->items->findInLibrary($itemId, $libraryId) === null) {
            throw new CatalogItemNotAvailable();
        }
        $details = $this->details->find($libraryId, $itemId);
        return $details === null
            ? ItemLocalDetailsView::absent()
            : ItemLocalDetailsView::fromDetails($details);
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, ItemLocalDetailsView>
     */
    public function detailsForItems(LibraryId $libraryId, array $itemIds): array
    {
        $this->libraryContexts->get($libraryId);
        if (count($itemIds) > self::MAX_BATCH) {
            throw new ValidationException("Item-local details batch exceeds 100 Items.");
        }
        $unique = [];
        foreach ($itemIds as $itemId) {
            if (isset($unique[$itemId->value()])) {
                throw new ValidationException("Item-local details batch contains duplicate Items.");
            }
            $unique[$itemId->value()] = $itemId;
        }

        $items = $this->items->findManyInLibrary($libraryId, $itemIds);
        foreach ($itemIds as $itemId) {
            if (($items[$itemId->value()] ?? null) === null) {
                throw new CatalogItemNotAvailable();
            }
        }
        $stored = $this->details->findMany($libraryId, $itemIds);
        $result = [];
        foreach ($itemIds as $itemId) {
            $details = $stored[$itemId->value()] ?? null;
            $result[$itemId->value()] = $details === null
                ? ItemLocalDetailsView::absent()
                : ItemLocalDetailsView::fromDetails($details);
        }
        return $result;
    }
}
