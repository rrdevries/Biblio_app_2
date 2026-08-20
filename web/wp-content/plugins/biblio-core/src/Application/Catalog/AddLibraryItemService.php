<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WritableEditionRepository;
use Biblio\Core\Catalog\WritableItemRepository;
use Biblio\Core\Catalog\WritableWorkRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;

final readonly class AddLibraryItemService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $libraryAccessService,
        private WritableWorkRepository $workRepository,
        private WritableEditionRepository $editionRepository,
        private WritableItemRepository $itemRepository,
        private TransactionManager $transactionManager
    ) {
    }

    public function addForExistingEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId
    ): Item {
        $context = $this->authorize($libraryId);

        if ($this->editionRepository->find($editionId) === null) {
            throw new ValidationException("Edition does not exist.");
        }

        $item = Item::active($itemId, $context->libraryId(), $editionId);

        return $this->transactionManager->run(function () use ($item): Item {
            $this->itemRepository->add($item);

            return $item;
        });
    }

    public function addWithNewEditionForExistingWork(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId,
        WorkId $workId
    ): Item {
        $context = $this->authorize($libraryId);

        if ($this->workRepository->find($workId) === null) {
            throw new ValidationException("Work does not exist.");
        }

        $edition = new Edition($editionId, $workId);
        $item = Item::active($itemId, $context->libraryId(), $editionId);

        return $this->transactionManager->run(function () use (
            $edition,
            $item
        ): Item {
            $this->editionRepository->add($edition);
            $this->itemRepository->add($item);

            return $item;
        });
    }

    public function addWithNewWorkAndEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        WorkId $workId,
        string $workTitle,
        EditionId $editionId
    ): Item {
        $context = $this->authorize($libraryId);
        $work = new Work($workId, $workTitle);
        $edition = new Edition($editionId, $workId);
        $item = Item::active($itemId, $context->libraryId(), $editionId);

        return $this->transactionManager->run(function () use (
            $work,
            $edition,
            $item
        ): Item {
            $this->workRepository->add($work);
            $this->editionRepository->add($edition);
            $this->itemRepository->add($item);

            return $item;
        });
    }

    private function authorize(LibraryId $libraryId): LibraryContext
    {
        $context = new LibraryContext(
            $libraryId,
            $this->authenticatedUser->requireUserId()
        );

        if (!$this->libraryAccessService->canAddCatalogItem($context)) {
            throw new AuthorizationException(
                "Adding catalog Items is not permitted for this Library."
            );
        }

        return $context;
    }
}
