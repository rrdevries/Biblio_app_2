<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingSource;
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
        UserId $authenticatedUserId,
        LibraryContext $context,
        ItemId $itemId,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $accessibleItem = $this->getAccessibleItem->get(
            $authenticatedUserId,
            $context,
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

        return $this->createReadingRound->create(
            $authenticatedUserId,
            $edition->workId(),
            ReadingSource::libraryItem($item->id()),
            $startedAt
        );
    }
}
