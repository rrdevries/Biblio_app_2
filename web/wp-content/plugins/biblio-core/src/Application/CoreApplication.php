<?php

declare(strict_types=1);

namespace Biblio\Core\Application;

use Biblio\Core\Application\Assessments\{AssessmentQueryService,CorrectRatingReadingRoundService,CorrectReviewReadingRoundService,CreateRatingForReadingRoundService,CreateRatingForWorkService,CreateReviewForReadingRoundService,CreateReviewForWorkService,DeleteOwnRatingService,DeleteOwnReviewService,ModerateContributionPublicationService,MoveContributionPublicationService,PublishRatingToLibraryService,PublishReviewToLibraryService,RestoreContributionPublicationService,UpdateRatingValueService,UpdateReviewContentService,WithdrawContributionPublicationService};
use Biblio\Core\Application\Assessments\Read\GetLibraryPublicAssessmentsService;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\ManageLibraryItemArchiveService;
use Biblio\Core\Application\Catalog\Query\CatalogQueryService;
use Biblio\Core\Application\Catalog\Read\CatalogUiReadService;
use Biblio\Core\Application\Catalog\Read\BibliographicRelationshipQueryService;
use Biblio\Core\Application\Catalog\Read\BibliographicMetadataQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemMetadataQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemLocationQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemArchiveQueryService;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryBookTypesService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\ManageLibrarySubjectsService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Collections\ManageLibraryCollectionsService;
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Notes\CorrectPrivateNoteReadingRoundService;
use Biblio\Core\Application\Notes\CreatePrivateNoteService;
use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\GetPrivateNoteService;
use Biblio\Core\Application\Notes\ListMyPrivateNotesService;
use Biblio\Core\Application\Notes\ListPrivateNotesForReadingRoundService;
use Biblio\Core\Application\Notes\ListPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\Read\GetMyPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Application\NextReading\{AddNextReadingEntryService,GetMyNextReadingListService,GetNextReadingHomeProjectionService,RemoveNextReadingEntryService,ReorderNextReadingListService,SetNextReadingPreferredSourceService,UndoNextReadingRemovalService};
use Biblio\Core\Application\NextReading\Read\NextReadingDiscoveryService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\CorrectEndedReadingRoundService;
use Biblio\Core\Application\Reading\CorrectReadingRoundSourceService;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Application\Reading\GetReadingSequenceService;
use Biblio\Core\Application\Reading\History\GetMyReadingHistoryForWorkService;
use Biblio\Core\Application\Reading\RegisterHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Application\Reading\StartReadingFromNextReadingEntryService;
use Biblio\Core\Application\Reading\StopReadingRoundService;

/**
 * The deliberately small adapter-facing boundary of the production Core.
 *
 * Concrete repositories and lower-level mutation primitives are composition
 * details and are intentionally not exposed here.
 */
final readonly class CoreApplication
{
    public function __construct(
        private EnsurePersonalPrivateLibraryService $personalLibraries,
        private LibraryContextQueryService $libraryContexts,
        private CatalogUiReadService $catalogUiReads,
        private CatalogQueryService $catalogQuery,
        private BibliographicRelationshipQueryService $bibliographicRelationships,
        private BibliographicMetadataQueryService $bibliographicMetadata,
        private LibraryItemMetadataQueryService $libraryItemMetadata,
        private LibraryItemLocationQueryService $libraryItemLocations,
        private LibraryItemArchiveQueryService $libraryItemArchives,
        private LibraryCollectionQueryService $libraryCollections,
        private LibraryClassificationQueryService $libraryClassifications,
        private AddLibraryItemService $libraryItemCreation,
        private ManageLibraryItemArchiveService $libraryItemArchiveManagement,
        private ManageLibraryCollectionsService $libraryCollectionManagement,
        private GetAccessibleLibraryItemService $accessibleLibraryItems,
        private GetOwnedExternalLoanService $ownedExternalLoans,
        private GetOwnedReadingRoundService $ownedReadingRounds,
        private StartReadingFromLibraryItemService $libraryItemReading,
        private StartReadingFromExternalLoanService $externalLoanReading,
        private StartReadingFromNextReadingEntryService $nextReadingEntryReading,
        private FinishReadingRoundService $finishReadingRound,
        private StopReadingRoundService $stopReadingRound,
        private RegisterHistoricalReadingRoundService $historicalReadingRounds,
        private CorrectEndedReadingRoundService $endedReadingRoundCorrection,
        private CorrectReadingRoundSourceService $readingRoundSourceCorrection,
        private DeleteHistoricalReadingRoundService $historicalReadingRoundDeletion,
        private GetPersonalWorkReadingStatusService $personalWorkReadingStatus,
        private GetReadingSequenceService $readingSequence,
        private GetMyReadingHistoryForWorkService $readingHistory,
        private CreatePrivateNoteService $privateNoteCreation,
        private UpdatePrivateNoteContentService $privateNoteContentUpdate,
        private CorrectPrivateNoteReadingRoundService $privateNoteContextCorrection,
        private DeletePrivateNoteService $privateNoteDeletion,
        private GetPrivateNoteService $privateNotes,
        private ListPrivateNotesForWorkService $privateNotesForWork,
        private ListPrivateNotesForReadingRoundService $privateNotesForReadingRound,
        private ListMyPrivateNotesService $myPrivateNotes,
        private RenderPrivateNoteContentService $privateNoteRendering,
        private GetMyPrivateNotesForWorkService $privateNoteViewsForWork,
        private CreateLibraryCatalogContextService $catalogContextCreation,
        private SaveLibraryCatalogContextService $catalogContextManagement,
        private ManageLibraryBookTypesService $bookTypeManagement,
        private ManageLibraryGenresService $genreManagement,
        private ManageLibrarySubjectsService $subjectManagement,
        private CreateRatingForWorkService $ratingForWorkCreation,
        private CreateRatingForReadingRoundService $ratingForRoundCreation,
        private UpdateRatingValueService $ratingValueUpdate,
        private CorrectRatingReadingRoundService $ratingContextCorrection,
        private DeleteOwnRatingService $ratingDeletion,
        private CreateReviewForWorkService $reviewForWorkCreation,
        private CreateReviewForReadingRoundService $reviewForRoundCreation,
        private UpdateReviewContentService $reviewContentUpdate,
        private CorrectReviewReadingRoundService $reviewContextCorrection,
        private DeleteOwnReviewService $reviewDeletion,
        private PublishRatingToLibraryService $ratingPublication,
        private PublishReviewToLibraryService $reviewPublication,
        private MoveContributionPublicationService $publicationMove,
        private WithdrawContributionPublicationService $publicationWithdrawal,
        private ModerateContributionPublicationService $publicationModeration,
        private RestoreContributionPublicationService $publicationRestoration,
        private AssessmentQueryService $assessmentQueries,
        private GetLibraryPublicAssessmentsService $libraryPublicAssessments,
        private AddNextReadingEntryService $nextReadingAdd,
        private RemoveNextReadingEntryService $nextReadingRemove,
        private UndoNextReadingRemovalService $nextReadingUndo,
        private SetNextReadingPreferredSourceService $nextReadingPreferredSource,
        private ReorderNextReadingListService $nextReadingReorder,
        private GetMyNextReadingListService $myNextReadingList,
        private GetNextReadingHomeProjectionService $nextReadingHome,
        private NextReadingDiscoveryService $nextReadingDiscovery
    ) {
    }

    public function libraryItemCreation(): AddLibraryItemService
    {
        return $this->libraryItemCreation;
    }

    public function personalLibraries(): EnsurePersonalPrivateLibraryService
    {
        return $this->personalLibraries;
    }

    public function libraryContexts(): LibraryContextQueryService
    {
        return $this->libraryContexts;
    }

    public function catalogUiReads(): CatalogUiReadService
    {
        return $this->catalogUiReads;
    }

    public function catalogQuery(): CatalogQueryService
    {
        return $this->catalogQuery;
    }

    public function bibliographicRelationships(): BibliographicRelationshipQueryService
    {
        return $this->bibliographicRelationships;
    }

    public function bibliographicMetadata(): BibliographicMetadataQueryService
    {
        return $this->bibliographicMetadata;
    }

    public function libraryItemMetadata(): LibraryItemMetadataQueryService
    {
        return $this->libraryItemMetadata;
    }

    public function libraryItemLocations(): LibraryItemLocationQueryService
    {
        return $this->libraryItemLocations;
    }

    public function libraryItemArchives(): LibraryItemArchiveQueryService
    {
        return $this->libraryItemArchives;
    }

    public function libraryItemArchiveManagement(): ManageLibraryItemArchiveService
    {
        return $this->libraryItemArchiveManagement;
    }

    public function libraryCollections(): LibraryCollectionQueryService
    {
        return $this->libraryCollections;
    }

    public function libraryClassifications(): LibraryClassificationQueryService
    {
        return $this->libraryClassifications;
    }

    public function libraryCollectionManagement(): ManageLibraryCollectionsService
    {
        return $this->libraryCollectionManagement;
    }

    public function accessibleLibraryItems(): GetAccessibleLibraryItemService
    {
        return $this->accessibleLibraryItems;
    }

    public function ownedExternalLoans(): GetOwnedExternalLoanService
    {
        return $this->ownedExternalLoans;
    }

    public function ownedReadingRounds(): GetOwnedReadingRoundService
    {
        return $this->ownedReadingRounds;
    }

    public function libraryItemReading(): StartReadingFromLibraryItemService
    {
        return $this->libraryItemReading;
    }

    public function externalLoanReading(): StartReadingFromExternalLoanService
    {
        return $this->externalLoanReading;
    }

    public function nextReadingEntryReading(): StartReadingFromNextReadingEntryService
    {
        return $this->nextReadingEntryReading;
    }

    public function finishReadingRound(): FinishReadingRoundService
    {
        return $this->finishReadingRound;
    }

    public function stopReadingRound(): StopReadingRoundService
    {
        return $this->stopReadingRound;
    }

    public function historicalReadingRounds(): RegisterHistoricalReadingRoundService
    {
        return $this->historicalReadingRounds;
    }

    public function endedReadingRoundCorrection(): CorrectEndedReadingRoundService
    {
        return $this->endedReadingRoundCorrection;
    }

    public function readingRoundSourceCorrection(): CorrectReadingRoundSourceService
    {
        return $this->readingRoundSourceCorrection;
    }

    public function historicalReadingRoundDeletion(): DeleteHistoricalReadingRoundService
    {
        return $this->historicalReadingRoundDeletion;
    }

    public function personalWorkReadingStatus(): GetPersonalWorkReadingStatusService
    {
        return $this->personalWorkReadingStatus;
    }

    public function readingSequence(): GetReadingSequenceService
    {
        return $this->readingSequence;
    }

    public function readingHistory(): GetMyReadingHistoryForWorkService
    {
        return $this->readingHistory;
    }

    public function privateNoteCreation(): CreatePrivateNoteService
    {
        return $this->privateNoteCreation;
    }

    public function privateNoteContentUpdate(): UpdatePrivateNoteContentService
    {
        return $this->privateNoteContentUpdate;
    }

    public function privateNoteContextCorrection(): CorrectPrivateNoteReadingRoundService
    {
        return $this->privateNoteContextCorrection;
    }

    public function privateNoteDeletion(): DeletePrivateNoteService
    {
        return $this->privateNoteDeletion;
    }

    public function privateNotes(): GetPrivateNoteService
    {
        return $this->privateNotes;
    }

    public function privateNotesForWork(): ListPrivateNotesForWorkService
    {
        return $this->privateNotesForWork;
    }

    public function privateNotesForReadingRound(): ListPrivateNotesForReadingRoundService
    {
        return $this->privateNotesForReadingRound;
    }

    public function myPrivateNotes(): ListMyPrivateNotesService
    {
        return $this->myPrivateNotes;
    }

    public function privateNoteRendering(): RenderPrivateNoteContentService
    {
        return $this->privateNoteRendering;
    }

    public function privateNoteViewsForWork(): GetMyPrivateNotesForWorkService
    {
        return $this->privateNoteViewsForWork;
    }

    public function catalogContextCreation(): CreateLibraryCatalogContextService
    {
        return $this->catalogContextCreation;
    }

    public function catalogContextManagement(): SaveLibraryCatalogContextService
    {
        return $this->catalogContextManagement;
    }

    public function bookTypeManagement(): ManageLibraryBookTypesService
    {
        return $this->bookTypeManagement;
    }

    public function genreManagement(): ManageLibraryGenresService
    {
        return $this->genreManagement;
    }

    public function subjectManagement(): ManageLibrarySubjectsService
    {
        return $this->subjectManagement;
    }

    public function ratingForWorkCreation(): CreateRatingForWorkService { return $this->ratingForWorkCreation; }
    public function ratingForRoundCreation(): CreateRatingForReadingRoundService { return $this->ratingForRoundCreation; }
    public function ratingValueUpdate(): UpdateRatingValueService { return $this->ratingValueUpdate; }
    public function ratingContextCorrection(): CorrectRatingReadingRoundService { return $this->ratingContextCorrection; }
    public function ratingDeletion(): DeleteOwnRatingService { return $this->ratingDeletion; }
    public function reviewForWorkCreation(): CreateReviewForWorkService { return $this->reviewForWorkCreation; }
    public function reviewForRoundCreation(): CreateReviewForReadingRoundService { return $this->reviewForRoundCreation; }
    public function reviewContentUpdate(): UpdateReviewContentService { return $this->reviewContentUpdate; }
    public function reviewContextCorrection(): CorrectReviewReadingRoundService { return $this->reviewContextCorrection; }
    public function reviewDeletion(): DeleteOwnReviewService { return $this->reviewDeletion; }
    public function ratingPublication(): PublishRatingToLibraryService { return $this->ratingPublication; }
    public function reviewPublication(): PublishReviewToLibraryService { return $this->reviewPublication; }
    public function publicationMove(): MoveContributionPublicationService { return $this->publicationMove; }
    public function publicationWithdrawal(): WithdrawContributionPublicationService { return $this->publicationWithdrawal; }
    public function publicationModeration(): ModerateContributionPublicationService { return $this->publicationModeration; }
    public function publicationRestoration(): RestoreContributionPublicationService { return $this->publicationRestoration; }
    public function assessmentQueries(): AssessmentQueryService { return $this->assessmentQueries; }
    public function libraryPublicAssessments(): GetLibraryPublicAssessmentsService { return $this->libraryPublicAssessments; }
    public function nextReadingAdd(): AddNextReadingEntryService { return $this->nextReadingAdd; }
    public function nextReadingRemove(): RemoveNextReadingEntryService { return $this->nextReadingRemove; }
    public function nextReadingUndo(): UndoNextReadingRemovalService { return $this->nextReadingUndo; }
    public function nextReadingPreferredSource(): SetNextReadingPreferredSourceService { return $this->nextReadingPreferredSource; }
    public function nextReadingReorder(): ReorderNextReadingListService { return $this->nextReadingReorder; }
    public function myNextReadingList(): GetMyNextReadingListService { return $this->myNextReadingList; }
    public function nextReadingHome(): GetNextReadingHomeProjectionService { return $this->nextReadingHome; }
    public function nextReadingDiscovery(): NextReadingDiscoveryService { return $this->nextReadingDiscovery; }
}
