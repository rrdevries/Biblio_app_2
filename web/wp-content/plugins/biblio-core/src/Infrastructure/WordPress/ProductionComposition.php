<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionConnection;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigration;
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

    /** @param list<CoreSchemaMigration> $migrations */
    public function __construct(
        wpdb $database,
        ?LifecycleStateStore $lifecycleState = null,
        array $migrations = []
    ) {
        $tableNames = new CoreTableNames($database->prefix);
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
        $externalLoanRepository = new WpdbExternalLoanRepository(
            $database,
            $tableNames
        );
        $readingRoundRepository = new WpdbReadingRoundRepository(
            $database,
            $tableNames
        );
        $createLibrary = new CreateLibraryService(
            $libraryRepository,
            $membershipRepository,
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
        $libraryItemCreation = new AddLibraryItemService(
            $authenticatedUser,
            $libraryAccess,
            $workRepository,
            $editionRepository,
            $itemRepository,
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
            $readingRoundRepository
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

        $this->application = new CoreApplication(
            $personalLibraries,
            $libraryItemCreation,
            $accessibleItems,
            $ownedExternalLoans,
            $ownedReadingRounds,
            $libraryItemReading,
            $externalLoanReading
        );
        $this->lifecycle = new CoreLifecycleCoordinator(
            new CoreSchemaMigrator($database, $tableNames, $migrations),
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
