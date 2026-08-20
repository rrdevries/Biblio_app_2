<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

final class CatalogApplicationPersistenceTest extends
    PersistenceIntegrationTestCase
{
    public function testProductionAuthorizationAndActorRefreshPerOperation(): void
    {
        $owner = $this->createWordPressUser("catalog-owner");
        $manager = $this->createWordPressUser("catalog-manager");
        $classificationManager = $this->createWordPressUser(
            "catalog-classification-manager"
        );
        $member = $this->createWordPressUser("catalog-member");
        $inactive = $this->createWordPressUser("catalog-inactive");
        $foreign = $this->createWordPressUser("catalog-foreign");
        $library = new LibraryId("library-a");
        $foreignLibrary = new LibraryId("library-b");
        $this->createOwnedLibrary($library, $owner);
        $this->createOwnedLibrary($foreignLibrary, $foreign);
        $this->addMembership(
            $library,
            $manager,
            ManagementRole::Manager,
            UseAccess::ViewOnly,
            permissions: AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD
            )
        );
        $this->addMembership(
            $library,
            $classificationManager,
            ManagementRole::Manager,
            UseAccess::Borrow,
            permissions: AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $this->addMembership(
            $library,
            $member,
            ManagementRole::Member,
            UseAccess::Direct,
            permissions: AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD,
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $this->addMembership(
            $library,
            $inactive,
            ManagementRole::Manager,
            UseAccess::Direct,
            MembershipStatus::Inactive,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD,
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $edition = new Edition(new EditionId("edition-existing"), $work->id());
        $this->workRepository()->add($work);
        $this->editionRepository()->add($edition);
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($owner);
            $application->libraryItemCreation()->addForExistingEdition(
                $library,
                new ItemId("item-owner"),
                $edition->id()
            );

            wp_set_current_user($manager);
            $application->libraryItemCreation()->addForExistingEdition(
                $library,
                new ItemId("item-manager"),
                $edition->id()
            );

            foreach (
                [$classificationManager, $member, $inactive, $foreign]
                as $deniedUser
            ) {
                wp_set_current_user($deniedUser);

                try {
                    $application->libraryItemCreation()
                        ->addForExistingEdition(
                            $library,
                            new ItemId("item-denied-{$deniedUser}"),
                            new EditionId("undisclosed-edition")
                        );
                    self::fail("Unauthorized catalog mutation was accepted.");
                } catch (AuthorizationException $exception) {
                    self::assertSame(
                        FailureReason::AuthorizationDenied,
                        $exception->reason()
                    );
                }
            }

            wp_set_current_user(0);

            try {
                $application->libraryItemCreation()->addForExistingEdition(
                    $library,
                    new ItemId("item-anonymous"),
                    $edition->id()
                );
                self::fail("Unauthenticated catalog mutation was accepted.");
            } catch (AuthenticationException $exception) {
                self::assertSame(
                    FailureReason::AuthenticationRequired,
                    $exception->reason()
                );
            }

            wp_set_current_user($owner);
            $application->libraryItemCreation()->addForExistingEdition(
                $library,
                new ItemId("item-owner-again"),
                $edition->id()
            );

            self::assertSame(1, $this->tableCount($this->tableNames->works()));
            self::assertSame(1, $this->tableCount($this->tableNames->editions()));
            self::assertSame(3, $this->tableCount($this->tableNames->items()));
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    public function testAllCreationPathsPreserveIsolationAndReadingSource(): void
    {
        $owner = $this->createWordPressUser("catalog-flow-owner");
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $this->createOwnedLibrary($libraryA, $owner);
        $this->createOwnedLibrary($libraryB, $owner);
        $existingWork = new Work(
            new WorkId("work-existing"),
            "Existing Work"
        );
        $this->workRepository()->add($existingWork);
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($owner);
            $itemA = $application->libraryItemCreation()
                ->addWithNewEditionForExistingWork(
                    $libraryA,
                    new ItemId("item-a"),
                    new EditionId("edition-shared"),
                    $existingWork->id()
                );
            $itemB = $application->libraryItemCreation()
                ->addForExistingEdition(
                    $libraryB,
                    new ItemId("item-b"),
                    new EditionId("edition-shared")
                );
            $readingItem = $application->libraryItemCreation()
                ->addWithNewWorkAndEdition(
                    $libraryA,
                    new ItemId("item-reading"),
                    new WorkId("work-reading"),
                    "Reading Work",
                    new EditionId("edition-reading")
                );

            self::assertNotNull(
                $application->accessibleLibraryItems()->get(
                    $libraryA,
                    $itemA->id()
                )
            );
            self::assertNotNull(
                $application->accessibleLibraryItems()->get(
                    $libraryB,
                    $itemB->id()
                )
            );
            self::assertNull(
                $application->accessibleLibraryItems()->get(
                    $libraryB,
                    $itemA->id()
                )
            );

            $round = $application->libraryItemReading()->start(
                $libraryA,
                $readingItem->id(),
                new DateTimeImmutable("2026-08-17T14:00:00.000000+00:00")
            );

            self::assertSame("work-reading", $round->workId()->value());
            self::assertSame(
                "item-reading",
                $round->source()->itemId()?->value()
            );
            self::assertSame(2, $this->tableCount($this->tableNames->works()));
            self::assertSame(2, $this->tableCount($this->tableNames->editions()));
            self::assertSame(3, $this->tableCount($this->tableNames->items()));
            self::assertSame(1, $this->tableCount(
                $this->tableNames->readingRounds()
            ));
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    #[DataProvider("compoundConflictCases")]
    public function testCompoundDuplicateConflictRollsBackEveryStep(
        string $path,
        string $failedStep
    ): void {
        $owner = $this->createWordPressUser(
            "rollback-{$path}-{$failedStep}"
        );
        $library = new LibraryId("library-a");
        $this->createOwnedLibrary($library, $owner);
        $workRepository = $this->workRepository();
        $editionRepository = $this->editionRepository();
        $itemRepository = $this->itemRepository();
        $existingWork = new Work(
            new WorkId("work-existing"),
            "Existing Work"
        );
        $workRepository->add($existingWork);

        $itemId = new ItemId("item-new");
        $editionId = new EditionId("edition-new");
        $workId = new WorkId("work-new");

        if ($failedStep === "work") {
            $workId = $existingWork->id();
        }

        if ($failedStep === "edition") {
            $editionId = new EditionId("edition-conflict");
            $editionRepository->add(new Edition(
                $editionId,
                $existingWork->id()
            ));
        }

        if ($failedStep === "item") {
            $seedEdition = new Edition(
                new EditionId("edition-seed"),
                $existingWork->id()
            );
            $editionRepository->add($seedEdition);
            $itemId = new ItemId("item-conflict");
            $itemRepository->add(Item::active(
                $itemId,
                $library,
                $seedEdition->id()
            ));
        }

        $countsBefore = $this->catalogCounts();
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($owner);

            try {
                if ($path === "new-edition") {
                    $application->libraryItemCreation()
                        ->addWithNewEditionForExistingWork(
                            $library,
                            $itemId,
                            $editionId,
                            $existingWork->id()
                        );
                } else {
                    $application->libraryItemCreation()
                        ->addWithNewWorkAndEdition(
                            $library,
                            $itemId,
                            $workId,
                            "New Work",
                            $editionId
                        );
                }
                self::fail("Duplicate catalog identifier was accepted.");
            } catch (CatalogRecordAlreadyExists $exception) {
                self::assertSame(
                    FailureReason::CatalogRecordAlreadyExists,
                    $exception->reason()
                );
                self::assertNotNull($exception->getPrevious());
            }

            self::assertSame($countsBefore, $this->catalogCounts());
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    public static function compoundConflictCases(): iterable
    {
        yield "new Edition duplicate Edition" => [
            "new-edition",
            "edition",
        ];
        yield "new Edition duplicate Item" => ["new-edition", "item"];
        yield "new Work duplicate Work" => ["new-work", "work"];
        yield "new Work duplicate Edition" => ["new-work", "edition"];
        yield "new Work duplicate Item" => ["new-work", "item"];
    }

    private function createOwnedLibrary(
        LibraryId $libraryId,
        int $wordpressUserId
    ): void {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ),
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        ))->create(
            Library::privateLibrary($libraryId),
            new UserId((string) $wordpressUserId)
        );
    }

    private function addMembership(
        LibraryId $libraryId,
        int $wordpressUserId,
        ManagementRole $role,
        UseAccess $useAccess,
        MembershipStatus $status = MembershipStatus::Active,
        ?AdditionalPermissions $permissions = null
    ): void {
        (new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        ))->add(new LibraryMembershipAssignment(
            $libraryId,
            new UserId((string) $wordpressUserId),
            new LibraryMembership($role, $useAccess, $status, $permissions)
        ));
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
        ]);

        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);

        return $result;
    }

    private function workRepository(): WpdbWorkRepository
    {
        return new WpdbWorkRepository($this->database, $this->tableNames);
    }

    private function editionRepository(): WpdbEditionRepository
    {
        return new WpdbEditionRepository($this->database, $this->tableNames);
    }

    private function itemRepository(): WpdbItemRepository
    {
        return new WpdbItemRepository($this->database, $this->tableNames);
    }

    private function catalogCounts(): array
    {
        return [
            "works" => $this->tableCount($this->tableNames->works()),
            "editions" => $this->tableCount($this->tableNames->editions()),
            "items" => $this->tableCount($this->tableNames->items()),
        ];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
