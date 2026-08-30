<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Assessments\{AssessmentQueryService,CorrectRatingReadingRoundService,CorrectReviewReadingRoundService,CreateRatingForReadingRoundService,CreateRatingForWorkService,CreateReviewForReadingRoundService,CreateReviewForWorkService,DeleteOwnRatingService,DeleteOwnReviewService,ModerateContributionPublicationService,MoveContributionPublicationService,PublicationService,PublishRatingToLibraryService,PublishReviewToLibraryService,RestoreContributionPublicationService,SourceContributionService,UpdateRatingValueService,UpdateReviewContentService,WithdrawContributionPublicationService};

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Read\CatalogUiReadService;
use Biblio\Core\Application\Catalog\Classification\ClassificationTermActivity;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryBookTypesService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\ManageLibrarySubjectsService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Notes\CorrectPrivateNoteReadingRoundService;
use Biblio\Core\Application\Notes\CreatePrivateNoteService;
use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\GetPrivateNoteService;
use Biblio\Core\Application\Notes\ListMyPrivateNotesService;
use Biblio\Core\Application\Notes\ListPrivateNotesForReadingRoundService;
use Biblio\Core\Application\Notes\ListPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\PrivateNoteCreation;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Application\NextReading\{AddExternalLoanToNextReadingService,AddLibraryItemToNextReadingService,AddWorkToNextReadingService,GetMyNextReadingListService,GetNextReadingHomeProjectionService,NextReadingMutation,NextReadingProjector,RemoveNextReadingEntryService,ReorderNextReadingListService};
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\CorrectEndedReadingRoundService;
use Biblio\Core\Application\Reading\CorrectReadingRoundSourceService;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Application\Reading\GetReadingSequenceService;
use Biblio\Core\Application\Reading\History\GetMyReadingHistoryForWorkService;
use Biblio\Core\Application\Reading\ReadingRoundCreation;
use Biblio\Core\Application\Reading\ReadingRoundEnd;
use Biblio\Core\Application\Reading\RegisterHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Application\Reading\StopReadingRoundService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActivityEventAppender;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibrarySubjectRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryWorkRepresentationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActorLibraryContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogUiReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPrivateNoteRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbNextReadingRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPublicationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbRatingRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingHistoryReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionConnection;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\CoreLifecycleCoordinator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\LifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\WpTransientLifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAuthenticatedUser;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use wpdb;

final class ProductionComposition
{
    private readonly CoreLifecycleCoordinator $lifecycle;
    private readonly CoreApplication $application;

    public function __construct(
        wpdb $database,
        ?LifecycleStateStore $lifecycleState = null,
        ?CoreSchemaMigrationRegistry $migrationRegistry = null
    ) {
        $tableNames = new CoreTableNames($database->prefix);
        $migrationRegistry ??= CoreSchemaMigrationRegistry::production(
            $database,
            $tableNames
        );
        $authenticatedUser = new WordPressAuthenticatedUser();
        $transactionConnection = new WpdbTransactionConnection($database);
        $transactionManager = new WpdbTransactionManager(
            $transactionConnection
        );
        $libraryRepository = new WpdbLibraryRepository(
            $database,
            $tableNames
        );
        $membershipRepository = new WpdbLibraryMembershipRepository(
            $database,
            $tableNames
        );
        $personalLibraryRepository = new WpdbPersonalLibraryRepository(
            $database,
            $tableNames
        );
        $workRepository = new WpdbWorkRepository($database, $tableNames);
        $editionRepository = new WpdbEditionRepository($database, $tableNames);
        $itemRepository = new WpdbItemRepository($database, $tableNames);
        $bookTypeRepository = new WpdbLibraryBookTypeRepository(
            $database,
            $tableNames
        );
        $genreRepository = new WpdbLibraryGenreRepository(
            $database,
            $tableNames
        );
        $subjectRepository = new WpdbLibrarySubjectRepository(
            $database,
            $tableNames
        );
        $catalogContextRepository = new WpdbLibraryCatalogContextRepository(
            $database,
            $tableNames
        );
        $libraryMutationLock = new WpdbLibraryMutationLock(
            $database,
            $tableNames
        );
        $representedWorks = new WpdbLibraryWorkRepresentationRepository(
            $database,
            $tableNames
        );
        $activityEvents = new WpdbActivityEventAppender(
            $database,
            $tableNames
        );
        $externalLoanRepository = new WpdbExternalLoanRepository(
            $database,
            $tableNames
        );
        $readingRoundRepository = new WpdbReadingRoundRepository(
            $database,
            $tableNames
        );
        $readingRoundIds = new OpaqueReadingRoundIdGenerator();
        $readingRoundClock = new SystemReadingRoundClock();
        $readingRoundCreation = new ReadingRoundCreation(
            $readingRoundIds,
            $readingRoundRepository
        );
        $seedEvolution = WpdbClassificationSeedEvolutionFactory::create(
            $database,
            $tableNames
        );
        $createLibrary = new CreateLibraryService(
            $libraryRepository,
            $membershipRepository,
            $seedEvolution,
            $transactionManager
        );
        $personalLibraries = new EnsurePersonalPrivateLibraryService(
            $authenticatedUser,
            $personalLibraryRepository,
            $createLibrary
        );
        $authorizationPolicy = new LibraryAuthorizationPolicy();
        $libraryAccess = new LibraryAccessService(
            $membershipRepository,
            $authorizationPolicy
        );
        $libraryContexts = new LibraryContextQueryService(
            $authenticatedUser,
            new WpdbActorLibraryContextRepository($database, $tableNames),
            $authorizationPolicy
        );
        $catalogUiReads = new CatalogUiReadService(
            $authenticatedUser,
            $libraryContexts,
            new WpdbCatalogUiReadRepository($database, $tableNames)
        );
        $activityFactory = new WordPressActivityEventFactory(
            new ActivityEventSource("core.classification")
        );
        $selectionResolver = new LibraryCatalogSelectionResolver(
            $bookTypeRepository,
            $genreRepository,
            $subjectRepository
        );
        $contextActivity = new LibraryCatalogContextActivity(
            $activityFactory
        );
        $contextInitializer = new LibraryCatalogContextInitializer(
            $catalogContextRepository,
            $selectionResolver,
            $libraryMutationLock
        );
        $libraryItemCreation = new AddLibraryItemService(
            $authenticatedUser,
            $libraryAccess,
            $workRepository,
            $editionRepository,
            $itemRepository,
            $catalogContextRepository,
            $contextInitializer,
            $contextActivity,
            $activityEvents,
            $transactionManager
        );
        $termActivity = new ClassificationTermActivity($activityFactory);
        $catalogContextCreation = new CreateLibraryCatalogContextService(
            $authenticatedUser,
            $libraryAccess,
            $representedWorks,
            $contextInitializer,
            $libraryMutationLock,
            $contextActivity,
            $activityEvents,
            $transactionManager
        );
        $catalogContextManagement = new SaveLibraryCatalogContextService(
            $authenticatedUser,
            $libraryAccess,
            $workRepository,
            $catalogContextRepository,
            $selectionResolver,
            $contextActivity,
            $activityEvents,
            $transactionManager
        );
        $normalizer = ClassificationNameNormalizer::create();
        $bookTypeManagement = new ManageLibraryBookTypesService(
            $authenticatedUser,
            $libraryAccess,
            $bookTypeRepository,
            $normalizer,
            $libraryMutationLock,
            $termActivity,
            $activityEvents,
            $transactionManager
        );
        $genreManagement = new ManageLibraryGenresService(
            $authenticatedUser,
            $libraryAccess,
            $genreRepository,
            $normalizer,
            $termActivity,
            $activityEvents,
            $transactionManager
        );
        $subjectManagement = new ManageLibrarySubjectsService(
            $authenticatedUser,
            $libraryAccess,
            $subjectRepository,
            $normalizer,
            $termActivity,
            $activityEvents,
            $transactionManager
        );
        $accessibleItems = new GetAccessibleLibraryItemService(
            $authenticatedUser,
            $itemRepository,
            $libraryAccess
        );
        $ownedExternalLoans = new GetOwnedExternalLoanService(
            $authenticatedUser,
            $externalLoanRepository
        );
        $createReadingRound = new CreateActiveReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository,
            $readingRoundIds,
            $readingRoundClock
        );
        $ownedReadingRounds = new GetOwnedReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository
        );
        $libraryItemReading = new StartReadingFromLibraryItemService(
            $accessibleItems,
            $editionRepository,
            $createReadingRound
        );
        $externalLoanReading = new StartReadingFromExternalLoanService(
            $ownedExternalLoans,
            $createReadingRound
        );
        $readingRoundEnd = new ReadingRoundEnd(
            $authenticatedUser,
            $readingRoundRepository,
            $readingRoundClock,
            $transactionManager
        );
        $finishReadingRound = new FinishReadingRoundService($readingRoundEnd);
        $stopReadingRound = new StopReadingRoundService($readingRoundEnd);
        $historicalReadingRounds = new RegisterHistoricalReadingRoundService(
            $authenticatedUser,
            $workRepository,
            $readingRoundCreation,
            $readingRoundClock,
            $transactionManager
        );
        $endedReadingRoundCorrection = new CorrectEndedReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository,
            $readingRoundClock,
            $transactionManager
        );
        $readingRoundSourceCorrection = new CorrectReadingRoundSourceService(
            $authenticatedUser,
            $readingRoundRepository,
            $accessibleItems,
            $editionRepository,
            $ownedExternalLoans,
            $readingRoundClock,
            $transactionManager
        );
        $assessmentClock = new SystemAssessmentClock();
        $ratingRepository = new WpdbRatingRepository($database, $tableNames);
        $reviewRepository = new WpdbReviewRepository($database, $tableNames);
        $publicationRepository = new WpdbPublicationRepository($database, $tableNames);
        $assessmentSources = new SourceContributionService(
            $authenticatedUser,
            $workRepository,
            $readingRoundRepository,
            $ratingRepository,
            $reviewRepository,
            new OpaqueRatingIdGenerator(),
            new OpaqueReviewIdGenerator(),
            $assessmentClock,
            $transactionManager
        );
        $publicationLifecycle = new PublicationService(
            $authenticatedUser,
            $libraryAccess,
            $libraryMutationLock,
            $representedWorks,
            $ratingRepository,
            $reviewRepository,
            $publicationRepository,
            new OpaquePublicationIdGenerator(),
            $assessmentClock,
            $transactionManager
        );
        $historicalReadingRoundDeletion = new DeleteHistoricalReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository,
            $transactionManager,
            $ratingRepository,
            $reviewRepository,
            $assessmentClock
        );
        $personalWorkReadingStatus = new GetPersonalWorkReadingStatusService(
            $authenticatedUser,
            $readingRoundRepository
        );
        $readingSequence = new GetReadingSequenceService(
            $authenticatedUser,
            $readingRoundRepository
        );
        $readingHistory = new GetMyReadingHistoryForWorkService(
            $authenticatedUser,
            new WpdbReadingHistoryReadRepository($database, $tableNames)
        );
        $privateNoteContentPolicy = new StrictPrivateNoteContentPolicy();
        $privateNoteRepository = new WpdbPrivateNoteRepository(
            $database,
            $tableNames,
            $privateNoteContentPolicy
        );
        $privateNoteClock = new SystemPrivateNoteClock();
        $privateNoteCreation = new PrivateNoteCreation(
            new OpaquePrivateNoteIdGenerator(),
            $privateNoteRepository
        );
        $privateNoteCreate = new CreatePrivateNoteService(
            $authenticatedUser,
            $workRepository,
            $readingRoundRepository,
            $privateNoteContentPolicy,
            $privateNoteCreation,
            $privateNoteClock,
            $transactionManager
        );
        $privateNoteContentUpdate = new UpdatePrivateNoteContentService(
            $authenticatedUser,
            $privateNoteRepository,
            $privateNoteContentPolicy,
            $privateNoteClock,
            $transactionManager
        );
        $privateNoteContextCorrection = new CorrectPrivateNoteReadingRoundService(
            $authenticatedUser,
            $privateNoteRepository,
            $readingRoundRepository,
            $privateNoteClock,
            $transactionManager
        );
        $privateNoteDeletion = new DeletePrivateNoteService(
            $authenticatedUser,
            $privateNoteRepository,
            $transactionManager
        );
        $privateNotes = new GetPrivateNoteService(
            $authenticatedUser,
            $privateNoteRepository
        );
        $privateNotesForWork = new ListPrivateNotesForWorkService(
            $authenticatedUser,
            $privateNoteRepository
        );
        $privateNotesForReadingRound = new ListPrivateNotesForReadingRoundService(
            $authenticatedUser,
            $privateNoteRepository,
            $readingRoundRepository
        );
        $myPrivateNotes = new ListMyPrivateNotesService(
            $authenticatedUser,
            $privateNoteRepository
        );
        $privateNoteRendering = new RenderPrivateNoteContentService(
            $privateNoteContentPolicy
        );
        $assessmentQueries = new AssessmentQueryService(
            $authenticatedUser,
            $libraryAccess,
            $ratingRepository,
            $reviewRepository,
            $publicationRepository
        );
        $nextReadingRepository = new WpdbNextReadingRepository($database, $tableNames);
        $nextReadingClock = new SystemNextReadingClock();
        $nextReadingMutation = new NextReadingMutation(
            $nextReadingRepository,
            new OpaqueNextReadingEntryIdGenerator(),
            $nextReadingClock,
            $transactionManager
        );
        $nextReadingProjector = new NextReadingProjector(
            $workRepository,
            $accessibleItems,
            $ownedExternalLoans
        );
        $nextReadingWorkAdd = new AddWorkToNextReadingService(
            $authenticatedUser,
            $workRepository,
            $nextReadingMutation
        );
        $nextReadingItemAdd = new AddLibraryItemToNextReadingService(
            $authenticatedUser,
            $accessibleItems,
            $editionRepository,
            $nextReadingMutation
        );
        $nextReadingExternalAdd = new AddExternalLoanToNextReadingService(
            $authenticatedUser,
            $ownedExternalLoans,
            $nextReadingMutation
        );
        $nextReadingRemove = new RemoveNextReadingEntryService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingClock,
            $transactionManager
        );
        $nextReadingReorder = new ReorderNextReadingListService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingClock,
            $transactionManager
        );
        $myNextReadingList = new GetMyNextReadingListService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingProjector
        );
        $nextReadingHome = new GetNextReadingHomeProjectionService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingProjector
        );

        $this->application = new CoreApplication(
            $personalLibraries,
            $libraryContexts,
            $catalogUiReads,
            $libraryItemCreation,
            $accessibleItems,
            $ownedExternalLoans,
            $ownedReadingRounds,
            $libraryItemReading,
            $externalLoanReading,
            $finishReadingRound,
            $stopReadingRound,
            $historicalReadingRounds,
            $endedReadingRoundCorrection,
            $readingRoundSourceCorrection,
            $historicalReadingRoundDeletion,
            $personalWorkReadingStatus,
            $readingSequence,
            $readingHistory,
            $privateNoteCreate,
            $privateNoteContentUpdate,
            $privateNoteContextCorrection,
            $privateNoteDeletion,
            $privateNotes,
            $privateNotesForWork,
            $privateNotesForReadingRound,
            $myPrivateNotes,
            $privateNoteRendering,
            $catalogContextCreation,
            $catalogContextManagement,
            $bookTypeManagement,
            $genreManagement,
            $subjectManagement,
            new CreateRatingForWorkService($assessmentSources),
            new CreateRatingForReadingRoundService($assessmentSources),
            new UpdateRatingValueService($assessmentSources),
            new CorrectRatingReadingRoundService($assessmentSources),
            new DeleteOwnRatingService($assessmentSources),
            new CreateReviewForWorkService($assessmentSources),
            new CreateReviewForReadingRoundService($assessmentSources),
            new UpdateReviewContentService($assessmentSources),
            new CorrectReviewReadingRoundService($assessmentSources),
            new DeleteOwnReviewService($assessmentSources),
            new PublishRatingToLibraryService($publicationLifecycle),
            new PublishReviewToLibraryService($publicationLifecycle),
            new MoveContributionPublicationService($publicationLifecycle),
            new WithdrawContributionPublicationService($publicationLifecycle),
            new ModerateContributionPublicationService($publicationLifecycle),
            new RestoreContributionPublicationService($publicationLifecycle),
            $assessmentQueries,
            $nextReadingWorkAdd,
            $nextReadingItemAdd,
            $nextReadingExternalAdd,
            $nextReadingRemove,
            $nextReadingReorder,
            $myNextReadingList,
            $nextReadingHome
        );
        $this->lifecycle = new CoreLifecycleCoordinator(
            new CoreSchemaMigrator(
                $database,
                $tableNames,
                $migrationRegistry->migrations()
            ),
            $lifecycleState ?? new WpTransientLifecycleStateStore()
        );
    }

    public function lifecycle(): CoreLifecycleCoordinator
    {
        return $this->lifecycle;
    }

    public function application(): CoreApplication
    {
        return $this->application;
    }
}
