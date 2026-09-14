<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Assessments\Read\GetLibraryPublicAssessmentsService;
use Biblio\Core\Application\Assessments\Read\GetOwnAssessmentsForWorkService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Collections\CollectionStatus;
use Biblio\Core\Library\LibraryId;

final readonly class CatalogUiReadService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryContextQueryService $libraryContexts,
        private CatalogUiReadRepository $repository,
        private LibraryClassificationQueryService $classifications,
        private LibraryCollectionQueryService $collections,
        private GetLibraryPublicAssessmentsService $publicAssessments,
        private GetOwnAssessmentsForWorkService $ownAssessments,
        private LibraryItemMetadataQueryService $itemMetadata,
        private LibraryItemLocationQueryService $itemLocations,
        private LibraryItemLocalDetailsQueryService $itemLocalDetails
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

        $classification = $this->classifications->assignedClassificationsForWorks(
            $libraryId,
            [$record->workId()]
        )[$record->workId()->value()] ?? null;
        $collectionIds = $this->collections->activeCollectionsForItems(
            $libraryId,
            [$record->itemId()]
        )[$record->itemId()->value()] ?? [];
        $collectionsById = $this->collections->collections(
            $libraryId,
            $collectionIds
        );
        $collections = [];
        foreach ($collectionIds as $collectionId) {
            $collection = $collectionsById[$collectionId->value()] ?? null;
            if (
                $collection === null
                || $collection->status() !== CollectionStatus::Active
            ) {
                continue;
            }
            $collections[] = new CatalogItemCollectionView(
                $collection->id(),
                $collection->name()->value()
            );
        }
        $assessments = $this->publicAssessments->forWork(
            $libraryId,
            $record->workId()
        );
        $ownAssessments = $this->ownAssessments->notVisibleInLibrary(
            $libraryId,
            $record->workId()
        );
        $inventoryNumber = $this->itemMetadata->inventoryNumbers(
            $libraryId,
            [$record->itemId()]
        )[$record->itemId()->value()] ?? null;
        $location = $this->itemLocations->locationsForItems(
            $libraryId,
            [$record->itemId()]
        )[$record->itemId()->value()] ?? null;
        $localDetails = $this->itemLocalDetails->details(
            $libraryId,
            $record->itemId()
        );
        $state = $localDetails->state();

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
            $inventoryNumber === null
                ? $unknown
                : CatalogTextValue::known($inventoryNumber->value()),
            $location === null
                ? $unknown
                : CatalogTextValue::known($location->displayName()),
            $state->condition() === null
                ? $unknown
                : CatalogTextValue::known($state->condition()->label()),
            $this->acquisitionSummary($state),
            $unknown,
            $localDetails,
            $classification,
            $collections,
            $assessments,
            $ownAssessments,
            $record->itemStatus(),
            new CatalogReadingSummary(
                $record->readingStatus(),
                $record->activeRoundCount(),
                $record->completedRoundCount(),
                $record->stoppedRoundCount(),
                $record->historicalCompletedRoundCount(),
                $record->readDateKnown()
            ),
            $record->activeReadingRound(),
            $this->capabilities($record, $library)
        );
    }

    private function acquisitionSummary(
        \Biblio\Core\Catalog\ItemLocalDetailsState $state
    ): CatalogTextValue {
        $date = $state->inLibrarySince();
        if ($date !== null) {
            $value = sprintf("%04d", $date->year());
            if ($date->month() !== null) {
                $value .= sprintf("-%02d", $date->month());
            }
            if ($date->day() !== null) {
                $value .= sprintf("-%02d", $date->day());
            }
            return CatalogTextValue::known($value);
        }
        $method = $state->acquisitionMethod();
        if ($method !== null) {
            return CatalogTextValue::known(match ($method) {
                \Biblio\Core\Catalog\AcquisitionMethod::Purchased => "Zelf aangeschaft",
                \Biblio\Core\Catalog\AcquisitionMethod::Received => "Gekregen",
                \Biblio\Core\Catalog\AcquisitionMethod::Other => "Anders",
            });
        }
        return CatalogTextValue::unknown();
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
            $this->capabilities($record, $library),
            $record->readDateKnown()
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
