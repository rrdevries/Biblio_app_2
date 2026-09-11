<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Assessments\{AssessmentQueryService,CorrectRatingReadingRoundService,CorrectReviewReadingRoundService,CreateRatingForReadingRoundService,CreateRatingForWorkService,CreateReviewForReadingRoundService,CreateReviewForWorkService,DeleteOwnRatingService,DeleteOwnReviewService,ModerateContributionPublicationService,MoveContributionPublicationService,PublicationService,PublishRatingToLibraryService,PublishReviewToLibraryService,RestoreContributionPublicationService,SourceContributionService,UpdateRatingValueService,UpdateReviewContentService,WithdrawContributionPublicationService};
use Biblio\Core\Application\Assessments\Read\{GetLibraryPublicAssessmentsService,GetOwnAssessmentsForWorkService};

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Discovery\WorkDiscoveryService;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Catalog\ItemArchiveActivity;
use Biblio\Core\Application\Catalog\ManageLibraryItemArchiveService;
use Biblio\Core\Application\Catalog\Query\{CatalogQueryCursorCodec,CatalogQueryService};
use Biblio\Core\Application\Catalog\Read\CatalogUiReadService;
use Biblio\Core\Application\Catalog\Read\BibliographicRelationshipQueryService;
use Biblio\Core\Application\Catalog\Read\BibliographicMetadataQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemMetadataQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemLocationQueryService;
use Biblio\Core\Application\Catalog\Read\LibraryItemArchiveQueryService;
use Biblio\Core\Application\Catalog\Classification\ClassificationTermActivity;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryBookTypesService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\ManageLibrarySubjectsService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\Collections\ManageLibraryCollectionsService;
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Application\Identity\PersonalMigrationTargetService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\ProvisionPersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Metadata\{AddBookCommitService,AddBookMetadataLookupService,AddBookMetadataReviewPolicy,CandidateClassifier,FirstSufficientMetadataLookupService};
use Biblio\Core\Application\Metadata\Discovery\{BibliographicDiscoveryService,BibliographicMaterializationService,BibliographicTextDiscoveryProvider,DesignatedPersonalBibliographicAuthorization};
use Biblio\Core\Application\Metadata\Search\{BibliographicAuthorWorkSearchProvider,BibliographicAuthorWorkSearchService,BibliographicEditionSearchService,BibliographicExternalEditionSearchProvider,BibliographicTextSearchService};
use Biblio\Core\Application\Notes\CorrectPrivateNoteReadingRoundService;
use Biblio\Core\Application\Notes\CreatePrivateNoteService;
use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\GetPrivateNoteService;
use Biblio\Core\Application\Notes\ListMyPrivateNotesService;
use Biblio\Core\Application\Notes\ListPrivateNotesForReadingRoundService;
use Biblio\Core\Application\Notes\ListPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\PrivateNoteCreation;
use Biblio\Core\Application\Notes\Read\GetMyPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Application\NextReading\{AddNextReadingEntryService,ConsumeNextReadingAfterStartService,GetMyNextReadingListService,GetNextReadingHomeProjectionService,NextReadingMutation,NextReadingProjector,RemoveNextReadingEntryService,ReorderNextReadingListService,SetNextReadingPreferredSourceService,UndoNextReadingRemovalService};
use Biblio\Core\Application\NextReading\Read\NextReadingDiscoveryService;
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
use Biblio\Core\Application\Reading\StartReadingFromNextReadingEntryService;
use Biblio\Core\Application\Reading\StopReadingRoundService;
use Biblio\Core\Application\Reading\PersonalReadingTruthRecorder;
use Biblio\Core\Application\Reading\RecordPersonalReadingTruthService;
use Biblio\Core\Application\Wishlist\{AddWishlistEntryService,GetMyWishlistService,RefineWishlistEntryService,RemoveWishlistEntryService,WishlistRecorder};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Collections\CollectionNameNormalizer;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionIdentifierClaimRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActivityEventAppender;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryClassificationReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibrarySubjectRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryWorkRepresentationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemArchiveRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionMetadataProvenanceRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataFieldReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataLookupSnapshotRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbUserObservedMetadataEvidenceRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCollectionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLocationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActorLibraryContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogUiReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbCatalogQueryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalMigrationTargetContentRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPrivateNoteRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbNextReadingRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbNextReadingDiscoveryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkDiscoveryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPublicationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbOwnAssessmentReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbRatingRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingHistoryReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalReadingTruthRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalWorkReadingMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionConnection;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWishlistReadRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWishlistRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbAuthorRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbSeriesRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicMetadataRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicLocalDiscoveryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicAuthorWorkSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicEditionSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorMetadataProvider;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorTextDiscoveryProvider;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorBibliographicSearchProvider;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorBibliographicAuthorWorkSearchProvider;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorBibliographicEditionSearchProvider;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\{GoogleBooksConfiguration,GoogleBooksMetadataProvider,GoogleBooksTextDiscoveryProvider};
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\{OpenLibraryAuthorWorkSearchProvider,OpenLibraryBibliographicSearchProvider,OpenLibraryConfiguration,OpenLibraryEditionSearchProvider,OpenLibraryMetadataProvider,OpenLibraryTextDiscoveryProvider};
use Biblio\Core\Infrastructure\Metadata\RuntimeMetadataProviderConfiguration;
use Biblio\Core\Infrastructure\Metadata\SystemMetadataClock;
use Biblio\Core\Infrastructure\Metadata\WordPressProviderHttpClient;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\CoreLifecycleCoordinator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\LifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\WpTransientLifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAuthenticatedUser;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressPlatformUserDirectory;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use wpdb;

final class ProductionComposition
{
    private readonly CoreLifecycleCoordinator $lifecycle;
    private readonly CoreApplication $application;
    private readonly RuntimeMetadataProviderConfiguration $providerConfiguration;

    public function __construct(
        wpdb $database,
        ?LifecycleStateStore $lifecycleState = null,
        ?CoreSchemaMigrationRegistry $migrationRegistry = null,
        ?FirstSufficientMetadataLookupService $metadataLookup = null,
        ?RuntimeMetadataProviderConfiguration $providerConfiguration = null
    ) {
        $this->providerConfiguration = $providerConfiguration
            ?? new RuntimeMetadataProviderConfiguration();
        $tableNames = new CoreTableNames($database->prefix);
        $migrationRegistry ??= CoreSchemaMigrationRegistry::production(
            $database,
            $tableNames
        );
        $authenticatedUser = new WordPressAuthenticatedUser();
        $platformUsers = new WordPressPlatformUserDirectory();
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
        $authorRepository = new WpdbAuthorRepository($database, $tableNames);
        $seriesRepository = new WpdbSeriesRepository($database, $tableNames);
        $editionRepository = new WpdbEditionRepository($database, $tableNames);
        $editionIdentifierClaims = new WpdbEditionIdentifierClaimRepository(
            $database,
            $tableNames
        );
        $metadataSnapshots = new WpdbMetadataLookupSnapshotRepository(
            $database,
            $tableNames
        );
        $bibliographicDiscoverySnapshots =
            new WpdbBibliographicDiscoverySnapshotRepository($database, $tableNames);
        $bibliographicProviderIdentities =
            new WpdbBibliographicProviderIdentityRepository($database, $tableNames);
        $metadataFieldReviews = new WpdbMetadataFieldReviewRepository(
            $database,
            $tableNames
        );
        $metadataObservations = new WpdbUserObservedMetadataEvidenceRepository(
            $database,
            $tableNames
        );
        $editionMetadataProvenance = new WpdbEditionMetadataProvenanceRepository(
            $database,
            $tableNames
        );
        $metadataClock = new SystemMetadataClock();
        $itemRepository = new WpdbItemRepository($database, $tableNames);
        $itemArchiveRepository = new WpdbItemArchiveRepository($database, $tableNames);
        $collectionRepository = new WpdbCollectionRepository($database, $tableNames);
        $locationRepository = new WpdbLocationRepository($database, $tableNames);
        $bibliographicMetadataRepository =
            new WpdbBibliographicMetadataRepository($database, $tableNames);
        $localEditionResolver = new LocalEditionResolver(
            new IsbnCanonicalizer(),
            $editionIdentifierClaims,
            $editionRepository,
            $bibliographicMetadataRepository
        );
        $metadataLookup ??= $this->metadataLookup();
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
        $personalReadingTruthRepository = new WpdbPersonalReadingTruthRepository(
            $database,
            $tableNames
        );
        $personalWorkReadingLock = new WpdbPersonalWorkReadingMutationLock(
            $database,
            $tableNames
        );
        $personalReadingTruthRecorder = new PersonalReadingTruthRecorder(
            $platformUsers,
            $workRepository,
            $readingRoundRepository,
            $personalReadingTruthRepository,
            $personalWorkReadingLock,
            new SystemPersonalReadingTruthClock()
        );
        $personalReadingTruthRecording = new RecordPersonalReadingTruthService(
            $authenticatedUser,
            $personalReadingTruthRecorder,
            $transactionManager
        );
        $nextReadingRepository = new WpdbNextReadingRepository($database, $tableNames);
        $nextReadingClock = new SystemNextReadingClock();
        $nextReadingConsumption = new ConsumeNextReadingAfterStartService(
            $nextReadingRepository,
            $nextReadingClock
        );
        $wishlistRepository = new WpdbWishlistRepository($database, $tableNames);
        $wishlistRecorder = new WishlistRecorder(
            $platformUsers,
            $workRepository,
            $editionRepository,
            $wishlistRepository,
            new OpaqueWishlistEntryIdGenerator(),
            new SystemWishlistClock()
        );
        $wishlistAdd = new AddWishlistEntryService(
            $authenticatedUser,
            $wishlistRecorder,
            $transactionManager
        );
        $wishlistRefine = new RefineWishlistEntryService(
            $authenticatedUser,
            $wishlistRecorder,
            $transactionManager
        );
        $wishlistRemove = new RemoveWishlistEntryService(
            $authenticatedUser,
            $wishlistRecorder,
            $transactionManager
        );
        $myWishlist = new GetMyWishlistService(
            $authenticatedUser,
            new WpdbWishlistReadRepository($database, $tableNames)
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
        $personalLibraryProvisioner = new ProvisionPersonalPrivateLibraryService(
            $personalLibraryRepository,
            $createLibrary
        );
        $personalLibraries = new EnsurePersonalPrivateLibraryService(
            $authenticatedUser,
            $personalLibraryProvisioner
        );
        $personalMigrationTargets = new PersonalMigrationTargetService(
            $platformUsers,
            $personalLibraryRepository,
            $libraryRepository,
            $membershipRepository,
            $personalLibraryProvisioner,
            new WpdbPersonalMigrationTargetContentRepository(
                $database,
                $tableNames
            )
        );
        $authorizationPolicy = new LibraryAuthorizationPolicy();
        $libraryAccess = new LibraryAccessService(
            $membershipRepository,
            $authorizationPolicy
        );
        $actorLibraryContexts = new WpdbActorLibraryContextRepository(
            $database,
            $tableNames
        );
        $libraryContexts = new LibraryContextQueryService(
            $authenticatedUser,
            $actorLibraryContexts,
            $authorizationPolicy
        );
        $libraryClassifications = new LibraryClassificationQueryService(
            $libraryContexts,
            new WpdbLibraryClassificationReadRepository($database, $tableNames)
        );
        $libraryCollections = new LibraryCollectionQueryService(
            $libraryContexts,
            $collectionRepository
        );
        $publicationRepository = new WpdbPublicationRepository(
            $database,
            $tableNames
        );
        $libraryPublicAssessments = new GetLibraryPublicAssessmentsService(
            $libraryContexts,
            $publicationRepository
        );
        $ownAssessments = new GetOwnAssessmentsForWorkService(
            $authenticatedUser,
            $libraryContexts,
            new WpdbOwnAssessmentReadRepository($database, $tableNames)
        );
        $catalogUiReads = new CatalogUiReadService(
            $authenticatedUser,
            $libraryContexts,
            new WpdbCatalogUiReadRepository($database, $tableNames),
            $libraryClassifications,
            $libraryCollections,
            $libraryPublicAssessments,
            $ownAssessments
        );
        $bibliographicRelationships = new BibliographicRelationshipQueryService(
            $authorRepository,
            $seriesRepository
        );
        $bibliographicMetadata = new BibliographicMetadataQueryService(
            $bibliographicMetadataRepository
        );
        $libraryItemMetadata = new LibraryItemMetadataQueryService(
            $libraryContexts,
            $itemRepository
        );
        $libraryItemLocations = new LibraryItemLocationQueryService(
            $libraryContexts,
            $locationRepository
        );
        $libraryItemArchives = new LibraryItemArchiveQueryService(
            $libraryContexts,
            $itemRepository,
            $itemArchiveRepository
        );
        $activityFactory = new WordPressActivityEventFactory(
            new ActivityEventSource("core.classification")
        );
        $itemArchiveActivity = new ItemArchiveActivity(
            new WordPressActivityEventFactory(
                new ActivityEventSource("core.item_archive")
            )
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
            $transactionManager,
            $localEditionResolver,
            $editionIdentifierClaims
        );
        $libraryItemArchiveManagement = new ManageLibraryItemArchiveService(
            $authenticatedUser,
            $libraryAccess,
            $itemArchiveRepository,
            $collectionRepository,
            new SystemItemArchiveClock(),
            $itemArchiveActivity,
            $activityEvents,
            $transactionManager
        );
        $libraryCollectionManagement = new ManageLibraryCollectionsService(
            $authenticatedUser,
            $libraryAccess,
            $itemRepository,
            $collectionRepository,
            $libraryMutationLock,
            new CollectionNameNormalizer(),
            new OpaqueCollectionIdGenerator(),
            new OpaqueCollectionMembershipIdGenerator(),
            new SystemCollectionClock(),
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
            $readingRoundClock,
            $transactionManager,
            $nextReadingConsumption,
            $personalWorkReadingLock
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
        $nextReadingEntryReading = new StartReadingFromNextReadingEntryService(
            $libraryItemReading,
            $externalLoanReading
        );
        $readingRoundEnd = new ReadingRoundEnd(
            $authenticatedUser,
            $readingRoundRepository,
            $readingRoundClock,
            $transactionManager,
            $personalWorkReadingLock
        );
        $finishReadingRound = new FinishReadingRoundService($readingRoundEnd);
        $stopReadingRound = new StopReadingRoundService($readingRoundEnd);
        $historicalReadingRounds = new RegisterHistoricalReadingRoundService(
            $authenticatedUser,
            $workRepository,
            $readingRoundCreation,
            $readingRoundClock,
            $transactionManager,
            $personalWorkReadingLock
        );
        $endedReadingRoundCorrection = new CorrectEndedReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository,
            $readingRoundClock,
            $transactionManager,
            $personalWorkReadingLock
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
            $assessmentClock,
            $personalWorkReadingLock
        );
        $personalWorkReadingStatus = new GetPersonalWorkReadingStatusService(
            $authenticatedUser,
            $readingRoundRepository,
            $personalReadingTruthRepository
        );
        $catalogQuery = new CatalogQueryService(
            $authenticatedUser,
            $libraryContexts,
            new WpdbCatalogQueryRepository($database, $tableNames),
            new CatalogQueryCursorCodec(hash('sha256', wp_salt('auth') . ':catalog-query-v1')),
            $bibliographicRelationships,
            $libraryClassifications,
            $libraryItemLocations,
            $libraryCollections,
            $personalWorkReadingStatus
        );
        $readingSequence = new GetReadingSequenceService(
            $authenticatedUser,
            $readingRoundRepository,
            $personalReadingTruthRepository
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
        $privateNoteViewsForWork = new GetMyPrivateNotesForWorkService(
            $authenticatedUser,
            $privateNoteRepository,
            $privateNoteRendering
        );
        $assessmentQueries = new AssessmentQueryService(
            $authenticatedUser,
            $libraryAccess,
            $ratingRepository,
            $reviewRepository,
            $publicationRepository
        );
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
        $nextReadingAdd = new AddNextReadingEntryService(
            $authenticatedUser,
            $workRepository,
            $accessibleItems,
            $editionRepository,
            $ownedExternalLoans,
            $nextReadingMutation
        );
        $nextReadingRemove = new RemoveNextReadingEntryService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingClock,
            new OpaqueNextReadingUndoTokenGenerator(),
            $transactionManager
        );
        $nextReadingUndo = new UndoNextReadingRemovalService(
            $authenticatedUser,
            $nextReadingRepository,
            $nextReadingClock,
            $transactionManager
        );
        $nextReadingPreferredSource = new SetNextReadingPreferredSourceService(
            $authenticatedUser,
            $nextReadingRepository,
            $accessibleItems,
            $editionRepository,
            $ownedExternalLoans,
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
        $workDiscovery = new WorkDiscoveryService(
            $authenticatedUser,
            new WpdbWorkDiscoveryRepository($database, $tableNames)
        );
        $nextReadingDiscovery = new NextReadingDiscoveryService(
            $authenticatedUser,
            $workRepository,
            $libraryContexts,
            new WpdbNextReadingDiscoveryRepository($database, $tableNames)
        );
        $addBookMetadataLookup = new AddBookMetadataLookupService(
            $libraryContexts,
            $localEditionResolver,
            $workRepository,
            $bibliographicRelationships,
            $itemRepository,
            $metadataLookup,
            new AddBookMetadataReviewPolicy(),
            $authenticatedUser,
            $metadataSnapshots,
            new OpaqueMetadataLookupIdGenerator(),
            $transactionManager,
            $metadataClock
        );
        $addBookCommit = new AddBookCommitService(
            $authenticatedUser,
            $libraryContexts,
            $localEditionResolver,
            $metadataSnapshots,
            $libraryItemCreation,
            new OpaqueAddBookRecordIdGenerator(),
            $metadataClock,
            $editionRepository,
            $workRepository,
            $metadataFieldReviews,
            $metadataObservations,
            $editionMetadataProvenance
        );
        [$primaryTextProvider, $fallbackTextProvider] = $this->textMetadataProviders();
        $bibliographicDiscovery = new BibliographicDiscoveryService(
            $authenticatedUser,
            new IsbnCanonicalizer(),
            new WpdbBibliographicLocalDiscoveryRepository($database, $tableNames),
            $localEditionResolver,
            $workRepository,
            $metadataLookup,
            $primaryTextProvider,
            $fallbackTextProvider,
            $bibliographicProviderIdentities,
            $bibliographicDiscoverySnapshots,
            new OpaqueMetadataLookupIdGenerator(),
            $metadataClock,
            $transactionManager
        );
        $bibliographicMaterialization = new BibliographicMaterializationService(
            $authenticatedUser,
            new DesignatedPersonalBibliographicAuthorization($actorLibraryContexts),
            $bibliographicDiscoverySnapshots,
            $bibliographicProviderIdentities,
            $localEditionResolver,
            $editionIdentifierClaims,
            $workRepository,
            $editionRepository,
            $metadataFieldReviews,
            new OpaqueBibliographicRecordIdGenerator(),
            $metadataClock,
            $transactionManager
        );
        $localBibliographicSearch = new WpdbBibliographicSearchProvider(
            $database,
            $tableNames
        );
        $openLibraryBibliographicSearch = $this->bibliographicSearchProvider();
        $bibliographicTextSearch = new BibliographicTextSearchService(
            $authenticatedUser,
            $localBibliographicSearch,
            $localBibliographicSearch,
            $openLibraryBibliographicSearch,
            $openLibraryBibliographicSearch,
            $bibliographicProviderIdentities
        );
        $bibliographicAuthorWorkSearch = new BibliographicAuthorWorkSearchService(
            $authenticatedUser,
            new WpdbBibliographicAuthorWorkSearchProvider($database, $tableNames),
            $this->bibliographicAuthorWorkSearchProvider(),
            $bibliographicProviderIdentities
        );
        $bibliographicEditionSearch = new BibliographicEditionSearchService(
            $authenticatedUser,
            new WpdbBibliographicEditionSearchProvider($database, $tableNames),
            $this->bibliographicEditionSearchProvider(),
            $bibliographicProviderIdentities,
            $bibliographicProviderIdentities,
            $editionIdentifierClaims,
            $editionRepository
        );

        $this->application = new CoreApplication(
            $personalLibraries,
            $personalMigrationTargets,
            $libraryContexts,
            $catalogUiReads,
            $catalogQuery,
            $bibliographicRelationships,
            $bibliographicMetadata,
            $libraryItemMetadata,
            $libraryItemLocations,
            $libraryItemArchives,
            $libraryCollections,
            $libraryClassifications,
            $addBookMetadataLookup,
            $addBookCommit,
            $libraryItemCreation,
            $libraryItemArchiveManagement,
            $libraryCollectionManagement,
            $accessibleItems,
            $ownedExternalLoans,
            $ownedReadingRounds,
            $libraryItemReading,
            $externalLoanReading,
            $nextReadingEntryReading,
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
            $privateNoteViewsForWork,
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
            $libraryPublicAssessments,
            $nextReadingAdd,
            $nextReadingRemove,
            $nextReadingUndo,
            $nextReadingPreferredSource,
            $nextReadingReorder,
            $myNextReadingList,
            $nextReadingHome,
            $workDiscovery,
            $nextReadingDiscovery,
            $personalReadingTruthRecording,
            $wishlistAdd,
            $wishlistRefine,
            $wishlistRemove,
            $myWishlist,
            $bibliographicTextSearch,
            $bibliographicAuthorWorkSearch,
            $bibliographicEditionSearch,
            $bibliographicDiscovery,
            $bibliographicMaterialization
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

    private function metadataLookup(): FirstSufficientMetadataLookupService
    {
        $http = new WordPressProviderHttpClient();
        $clock = new SystemMetadataClock();
        $openLibrary = new ConfigurationErrorMetadataProvider("open_library");
        $contact = $this->providerConfiguration->openLibraryContactEmail();

        if (is_string($contact)) {
            try {
                $openLibrary = new OpenLibraryMetadataProvider(
                    $http,
                    $clock,
                    new IsbnCanonicalizer(),
                    new OpenLibraryConfiguration("Biblio", "2.001", $contact)
                );
            } catch (\InvalidArgumentException) {
                // Invalid operational config degrades to a controlled result.
            }
        }

        $google = new ConfigurationErrorMetadataProvider("google_books");
        $apiKey = $this->providerConfiguration->googleBooksApiKey();

        try {
            $google = new GoogleBooksMetadataProvider(
                $http,
                $clock,
                new IsbnCanonicalizer(),
                new GoogleBooksConfiguration($apiKey)
            );
        } catch (\InvalidArgumentException) {
            // Invalid operational config degrades to a controlled result.
        }

        return new FirstSufficientMetadataLookupService(
            new CandidateClassifier(),
            $openLibrary,
            $google
        );
    }

    private function bibliographicSearchProvider(): OpenLibraryBibliographicSearchProvider|ConfigurationErrorBibliographicSearchProvider
    {
        $provider = new ConfigurationErrorBibliographicSearchProvider("open_library");
        $contact = $this->providerConfiguration->openLibraryContactEmail();
        if (is_string($contact)) {
            try {
                $provider = new OpenLibraryBibliographicSearchProvider(
                    new WordPressProviderHttpClient(),
                    new OpenLibraryConfiguration("Biblio", "2.001", $contact)
                );
            } catch (\InvalidArgumentException) {
                // Invalid operational config remains a typed configuration failure.
            }
        }
        return $provider;
    }

    private function bibliographicEditionSearchProvider(): BibliographicExternalEditionSearchProvider
    {
        $provider = new ConfigurationErrorBibliographicEditionSearchProvider("open_library");
        $contact = $this->providerConfiguration->openLibraryContactEmail();
        if (is_string($contact)) {
            try {
                $provider = new OpenLibraryEditionSearchProvider(
                    new WordPressProviderHttpClient(),
                    new SystemMetadataClock(),
                    new IsbnCanonicalizer(),
                    new OpenLibraryConfiguration("Biblio", "2.001", $contact)
                );
            } catch (\InvalidArgumentException) {
                // Invalid operational config remains a typed configuration failure.
            }
        }
        return $provider;
    }

    private function bibliographicAuthorWorkSearchProvider(): BibliographicAuthorWorkSearchProvider
    {
        $provider = new ConfigurationErrorBibliographicAuthorWorkSearchProvider("open_library");
        $contact = $this->providerConfiguration->openLibraryContactEmail();
        if (is_string($contact)) {
            try {
                $provider = new OpenLibraryAuthorWorkSearchProvider(
                    new WordPressProviderHttpClient(),
                    new OpenLibraryConfiguration("Biblio", "2.001", $contact)
                );
            } catch (\InvalidArgumentException) {
                // Invalid operational config remains a typed configuration failure.
            }
        }
        return $provider;
    }

    /** @return array{BibliographicTextDiscoveryProvider,BibliographicTextDiscoveryProvider} */
    private function textMetadataProviders(): array
    {
        $http = new WordPressProviderHttpClient();
        $clock = new SystemMetadataClock();
        $canonicalizer = new IsbnCanonicalizer();
        $openLibrary = new ConfigurationErrorTextDiscoveryProvider("open_library");
        $contact = $this->providerConfiguration->openLibraryContactEmail();
        if (is_string($contact)) {
            try {
                $openLibrary = new OpenLibraryTextDiscoveryProvider(
                    $http,
                    $clock,
                    $canonicalizer,
                    new OpenLibraryConfiguration("Biblio", "2.001", $contact)
                );
            } catch (\InvalidArgumentException) {
                // Invalid operational config remains a typed configuration failure.
            }
        }

        $google = new ConfigurationErrorTextDiscoveryProvider("google_books");
        $apiKey = $this->providerConfiguration->googleBooksApiKey();
        try {
            $google = new GoogleBooksTextDiscoveryProvider(
                $http,
                $clock,
                $canonicalizer,
                new GoogleBooksConfiguration($apiKey)
            );
        } catch (\InvalidArgumentException) {
            // Invalid operational config remains a typed configuration failure.
        }

        return [$openLibrary, $google];
    }
}
