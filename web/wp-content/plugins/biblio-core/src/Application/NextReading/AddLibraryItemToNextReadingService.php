<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Catalog\{EditionRepository,ItemId};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingList,NextReadingTarget,NextReadingTargetUnavailable};

final readonly class AddLibraryItemToNextReadingService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private GetAccessibleLibraryItemService $items,
        private EditionRepository $editions,
        private NextReadingMutation $mutation
    ) {
    }

    public function add(LibraryId $libraryId, ItemId $itemId): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $accessible = $this->items->get($libraryId, $itemId);
        if ($accessible === null) {
            throw new NextReadingTargetUnavailable();
        }
        $item = $accessible->item();
        $edition = $this->editions->find($item->editionId());
        if ($edition === null) {
            throw new NextReadingTargetUnavailable();
        }
        return $this->mutation->append(
            $actorId,
            NextReadingTarget::forLibraryItem($edition->workId(), $item->id(), $libraryId)
        );
    }
}
