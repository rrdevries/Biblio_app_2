<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
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
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\CorrectEndedReadingRoundService;
use Biblio\Core\Application\Reading\CorrectReadingRoundSourceService;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Application\Reading\GetReadingSequenceService;
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
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionConnection;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\CoreLifecycleCoordinator;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\LifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\WpTransientLifecycleStateStore;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAuthenticatedUser;
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
        $libraryAccess = new LibraryAccessService(
            $membershipRepository,
            new LibraryAuthorizationPolicy()
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
        $historicalReadingRoundDeletion = new DeleteHistoricalReadingRoundService(
            $authenticatedUser,
            $readingRoundRepository,
            $transactionManager
        );
        $personalWorkReadingStatus = new GetPersonalWorkReadingStatusService(
            $authenticatedUser,
            $readingRoundRepository
        );
        $readingSequence = new GetReadingSequenceService(
            $authenticatedUser,
            $readingRoundRepository
        );

        $this->application = new CoreApplication(
            $personalLibraries,
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
            $catalogContextCreation,
            $catalogContextManagement,
            $bookTypeManagement,
            $genreManagement,
            $subjectManagement
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
