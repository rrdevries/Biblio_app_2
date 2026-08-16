<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;

final readonly class GetAccessibleLibraryItemService
{
    public function __construct(
        private ItemRepository $itemRepository,
        private LibraryAccessService $libraryAccessService
    ) {
    }

    public function get(
        UserId $authenticatedUserId,
        LibraryContext $context,
        ItemId $itemId
    ): ?AccessibleLibraryItem {
        if (!$authenticatedUserId->equals($context->userId())) {
            return null;
        }

        if (!$this->libraryAccessService->canViewCollection($context)) {
            return null;
        }

        $item = $this->itemRepository->findInLibrary(
            $itemId,
            $context->libraryId()
        );

        if (
            $item === null
            || !$context->libraryId()->equals($item->libraryId())
        ) {
            return null;
        }

        return new AccessibleLibraryItem(
            $item,
            $this->libraryAccessService->canUseItemDirectly($context)
        );
    }
}
