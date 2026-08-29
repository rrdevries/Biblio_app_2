<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;

final readonly class CatalogUiReadService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryContextQueryService $libraryContexts,
        private CatalogUiReadRepository $repository
    ) {
    }

    public function activeOverview(
        LibraryId $libraryId,
        ?CatalogOverviewCursor $cursor = null,
        ?CatalogOverviewPageSize $pageSize = null
    ): CatalogOverviewView {
        $library = $this->libraryContexts->get($libraryId);
        $actorId = $this->authenticatedUser->requireUserId();
        $page = $this->repository->activeOverview(
            $libraryId,
            $actorId,
            $pageSize ?? new CatalogOverviewPageSize(),
            $cursor
        );

        return new CatalogOverviewView(
            $library,
            array_map(
                fn (CatalogItemReadRecord $record): CatalogItemCardView =>
                    $this->card($record, $library),
                $page->records()
            ),
            $page->nextCursor()
        );
    }

    public function itemDetail(
        LibraryId $libraryId,
        ItemId $itemId
    ): CatalogItemDetailView {
        $library = $this->libraryContexts->get($libraryId);
        $actorId = $this->authenticatedUser->requireUserId();
        $record = $this->repository->activeDetail($libraryId, $itemId, $actorId);

        if ($record === null) {
            throw new CatalogItemNotAvailable();
        }

        $unknown = CatalogTextValue::unknown();

        return new CatalogItemDetailView(
            $library,
            $record->itemId(),
            $record->workId(),
            $record->editionId(),
            $record->title(),
            CatalogTextListValue::unknown(),
            $unknown,
            $unknown,
            $unknown,
            $unknown,
            $unknown,
            $unknown,
            CatalogTextValue::known("physical_book"),
            $unknown,
            $unknown,
            $unknown,
            $unknown,
            $record->itemStatus(),
            new CatalogReadingSummary(
                $record->readingStatus(),
                $record->activeRoundCount(),
                $record->completedRoundCount(),
                $record->stoppedRoundCount(),
                $record->historicalCompletedRoundCount()
            ),
            $record->activeReadingRound(),
            $this->capabilities($record, $library)
        );
    }

    private function card(
        CatalogItemReadRecord $record,
        LibraryContextView $library
    ): CatalogItemCardView {
        return new CatalogItemCardView(
            $record->itemId(),
            $record->workId(),
            $record->editionId(),
            $record->title(),
            CatalogTextListValue::unknown(),
            CatalogTextValue::unknown(),
            CatalogTextValue::known("physical_book"),
            CatalogTextValue::known($library->name()->value()),
            $record->readingStatus(),
            $record->itemStatus(),
            $this->capabilities($record, $library)
        );
    }

    private function capabilities(
        CatalogItemReadRecord $record,
        LibraryContextView $library
    ): CatalogItemCapabilities {
        return new CatalogItemCapabilities(
            $library->capabilities()->canViewCollection(),
            $library->capabilities()->canUseItemDirectly()
                && !$record->hasActiveRoundForItem(),
            $record->hasActiveRoundForItem()
        );
    }
}
