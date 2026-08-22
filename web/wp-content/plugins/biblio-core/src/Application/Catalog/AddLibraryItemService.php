<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializationResult;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
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
use Biblio\Core\Identity\UserId;
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
        private LibraryCatalogContextRepository $catalogContexts,
        private LibraryCatalogContextInitializer $contextInitializer,
        private LibraryCatalogContextActivity $contextActivity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactionManager
    ) {
    }

    public function addForExistingEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId,
        ?LibraryCatalogContextInitialization $classification = null
    ): Item {
        $context = $this->authorize($libraryId);
        $actorId = $context->userId();
        $edition = $this->editionRepository->find($editionId);

        if ($edition === null) {
            throw new ValidationException("Edition does not exist.");
        }

        $work = $this->workRepository->find($edition->workId());

        if ($work === null) {
            throw new ValidationException("Edition Work does not exist.");
        }

        $contextExists = $this->catalogContexts->find(
            $libraryId,
            $work->id()
        ) !== null;
        $item = Item::active($itemId, $context->libraryId(), $editionId);

        return $this->transactionManager->run(function () use (
            $actorId,
            $libraryId,
            $work,
            $classification,
            $contextExists,
            $item
        ): Item {
            $initialization = $this->initializeContextWhenMissing(
                $libraryId,
                $work->id(),
                $classification,
                $contextExists
            );
            $this->itemRepository->add($item);
            $this->appendContextCreatedEvent(
                $actorId,
                $libraryId,
                $work,
                $initialization
            );

            return $item;
        });
    }

    public function addWithNewEditionForExistingWork(
        LibraryId $libraryId,
        ItemId $itemId,
        EditionId $editionId,
        WorkId $workId,
        ?LibraryCatalogContextInitialization $classification = null
    ): Item {
        $context = $this->authorize($libraryId);
        $actorId = $context->userId();
        $work = $this->workRepository->find($workId);

        if ($work === null) {
            throw new ValidationException("Work does not exist.");
        }

        $contextExists = $this->catalogContexts->find(
            $libraryId,
            $workId
        ) !== null;
        $edition = new Edition($editionId, $workId);
        $item = Item::active($itemId, $context->libraryId(), $editionId);

        return $this->transactionManager->run(function () use (
            $actorId,
            $libraryId,
            $work,
            $classification,
            $contextExists,
            $edition,
            $item
        ): Item {
            $this->editionRepository->add($edition);
            $initialization = $this->initializeContextWhenMissing(
                $libraryId,
                $work->id(),
                $classification,
                $contextExists
            );
            $this->itemRepository->add($item);
            $this->appendContextCreatedEvent(
                $actorId,
                $libraryId,
                $work,
                $initialization
            );

            return $item;
        });
    }

    public function addWithNewWorkAndEdition(
        LibraryId $libraryId,
        ItemId $itemId,
        WorkId $workId,
        string $workTitle,
        EditionId $editionId,
        ?LibraryCatalogContextInitialization $classification = null
    ): Item {
        $context = $this->authorize($libraryId);
        $actorId = $context->userId();
        $work = new Work($workId, $workTitle);
        $edition = new Edition($editionId, $workId);
        $item = Item::active($itemId, $context->libraryId(), $editionId);
        $contextExists = $this->catalogContexts->find(
            $libraryId,
            $workId
        ) !== null;

        return $this->transactionManager->run(function () use (
            $actorId,
            $libraryId,
            $classification,
            $contextExists,
            $work,
            $edition,
            $item
        ): Item {
            $this->workRepository->add($work);
            $this->editionRepository->add($edition);
            $initialization = $this->initializeContextWhenMissing(
                $libraryId,
                $work->id(),
                $classification,
                $contextExists
            );
            $this->itemRepository->add($item);
            $this->appendContextCreatedEvent(
                $actorId,
                $libraryId,
                $work,
                $initialization
            );

            return $item;
        });
    }

    private function initializeContextWhenMissing(
        LibraryId $libraryId,
        WorkId $workId,
        ?LibraryCatalogContextInitialization $classification,
        bool $contextExists
    ): ?LibraryCatalogContextInitializationResult {
        if ($contextExists) {
            return null;
        }

        return $this->contextInitializer->initializeOrReuse(
            $libraryId,
            $workId,
            $classification?->selection()
        );
    }

    private function appendContextCreatedEvent(
        UserId $actorId,
        LibraryId $libraryId,
        Work $work,
        ?LibraryCatalogContextInitializationResult $initialization
    ): void {
        $selection = $initialization?->createdSelection();

        if ($selection === null) {
            return;
        }

        $this->activityEvents->append($this->contextActivity->created(
            $actorId,
            $libraryId,
            $work,
            $selection
        ));
    }

    private function authorize(LibraryId $libraryId): LibraryContext
    {
        $context = new LibraryContext(
            $libraryId,
            $this->authenticatedUser->requireUserId()
        );

        if (
            !$this->libraryAccessService->canAddCatalogItem($context)
            || !$this->libraryAccessService
                ->canInitializeCatalogContextDuringItemAdd($context)
        ) {
            throw new AuthorizationException(
                "Adding catalog Items is not permitted for this Library."
            );
        }

        return $context;
    }
}
