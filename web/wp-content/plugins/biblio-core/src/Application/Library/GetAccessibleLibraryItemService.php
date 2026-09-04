<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;

final readonly class GetAccessibleLibraryItemService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ItemRepository $itemRepository,
        private LibraryAccessService $libraryAccessService
    ) {
    }

    public function get(
        LibraryId $libraryId,
        ItemId $itemId
    ): ?AccessibleLibraryItem {
        $context = new LibraryContext(
            $libraryId,
            $this->authenticatedUser->requireUserId()
        );

        if (!$this->libraryAccessService->canViewCollection($context)) {
            return null;
        }

        $item = $this->itemRepository->findInLibrary(
            $itemId,
            $context->libraryId()
        );

        if (
            $item === null
            || $item->status() !== ItemStatus::Active
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
