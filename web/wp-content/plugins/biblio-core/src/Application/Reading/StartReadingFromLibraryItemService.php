<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use DateTimeImmutable;

final readonly class StartReadingFromLibraryItemService
{
    public function __construct(
        private GetAccessibleLibraryItemService $getAccessibleItem,
        private EditionRepository $editionRepository,
        private CreateActiveReadingRoundService $createReadingRound
    ) {
    }

    public function start(
        LibraryId $libraryId,
        ItemId $itemId,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $accessibleItem = $this->getAccessibleItem->get(
            $libraryId,
            $itemId
        );

        if (
            $accessibleItem === null
            || !$accessibleItem->canUseAsDirectSource()
            || $accessibleItem->item()->status() !== ItemStatus::Active
        ) {
            throw new ReadingSourceUnavailable();
        }

        $item = $accessibleItem->item();
        $edition = $this->editionRepository->find($item->editionId());

        if ($edition === null) {
            throw new ReadingSourceUnavailable();
        }

        return $this->createReadingRound->createFromLibraryItem(
            $item,
            $edition,
            $startedAt
        );
    }
}
