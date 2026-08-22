<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
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
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
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
            wp_set_current_user($manager);
            $application->libraryItemCreation()->addForExistingEdition(
                $library,
                new ItemId("item-manager"),
                $edition->id(),
                $this->initialization($library)
            );

            wp_set_current_user($owner);
            $application->libraryItemCreation()->addForExistingEdition(
                $library,
                new ItemId("item-owner"),
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
                    $existingWork->id(),
                    $this->initialization($libraryA)
                );
            $itemB = $application->libraryItemCreation()
                ->addForExistingEdition(
                    $libraryB,
                    new ItemId("item-b"),
                    new EditionId("edition-shared"),
                    $this->initialization($libraryB)
                );
            $readingItem = $application->libraryItemCreation()
                ->addWithNewWorkAndEdition(
                    $libraryA,
                    new ItemId("item-reading"),
                    new WorkId("work-reading"),
                    "Reading Work",
                    new EditionId("edition-reading"),
                    $this->initialization($libraryA)
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

    public function testItemAddCreatesOrReusesContextWithAtomicAudit(): void
    {
        $owner = $this->createWordPressUser("catalog-context-owner");
        $libraryA = new LibraryId("library-context-a");
        $libraryB = new LibraryId("library-context-b");
        $this->createOwnedLibrary($libraryA, $owner);
        $this->createOwnedLibrary($libraryB, $owner);
        $work = new Work(new WorkId("work-context"), "Context Work");
        $edition = new Edition(new EditionId("edition-context"), $work->id());
        $this->workRepository()->add($work);
        $this->editionRepository()->add($edition);
        $book = $this->seedBookType($libraryA, "book_type.reading_book");
        $otherBook = $this->seedBookType($libraryA, "book_type.cookbook");
        $genre = $this->seedGenre($libraryA, "genre.fantasy");
        $foreignBook = $this->seedBookType(
            $libraryB,
            "book_type.reading_book"
        );
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($owner);
            $application->libraryItemCreation()->addForExistingEdition(
                $libraryA,
                new ItemId("item-context-first"),
                $edition->id(),
                new LibraryCatalogContextInitialization(
                    new LibraryCatalogSelection($book, [$genre])
                )
            );

            $contexts = new WpdbLibraryCatalogContextRepository(
                $this->database,
                $this->tableNames
            );
            $stored = $contexts->find($libraryA, $work->id());
            self::assertNotNull($stored);
            self::assertSame(1, $stored->version()->value());
            self::assertTrue($book->equals(
                $stored->classification()->bookTypeId()
            ));
            self::assertSame(
                [$genre->value()],
                array_map(
                    static fn (LibraryGenreId $id): string => $id->value(),
                    $stored->classification()->genreIds()
                )
            );
            self::assertSame([], $stored->classification()->subjectIds());

            $event = $this->contextCreatedEvent($libraryA, $work->id());
            self::assertSame("LibraryCatalogContext", $event->primary_entity_type);
            self::assertSame($work->id()->value(), $event->primary_entity_id);
            $related = json_decode(
                $event->related_entities_json,
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $changes = json_decode(
                $event->changes_json,
                true,
                flags: JSON_THROW_ON_ERROR
            );
            self::assertSame("Work", $related[0]["identity"]["entity_type"]);
            self::assertSame($work->id()->value(), $related[0]["identity"]["entity_id"]);
            self::assertSame("Context Work", $related[0]["display_label"]);
            self::assertSame(
                $book->value(),
                $changes[0]["new_value"]["id"]
            );
            self::assertSame("Leesboek", $changes[0]["new_value"]["label"]);
            self::assertSame(
                $genre->value(),
                $changes[1]["new_value"]["terms"][0]["id"]
            );
            self::assertSame(
                "Fantasy",
                $changes[1]["new_value"]["terms"][0]["label"]
            );

            $application->bookTypeManagement()->deactivate(
                $libraryA,
                $book,
                false
            );
            $application->libraryItemCreation()->addForExistingEdition(
                $libraryA,
                new ItemId("item-context-reused"),
                $edition->id(),
                new LibraryCatalogContextInitialization(
                    new LibraryCatalogSelection($otherBook)
                )
            );
            $reused = $contexts->find($libraryA, $work->id());
            self::assertNotNull($reused);
            self::assertSame(1, $reused->version()->value());
            self::assertTrue($book->equals(
                $reused->classification()->bookTypeId()
            ));
            self::assertSame(
                1,
                $this->contextCreatedEventCount($libraryA, $work->id())
            );

            $this->assertInvalidNewContextRollsBack(
                $application,
                $libraryA,
                new WorkId("work-without-classification"),
                null
            );
            $this->assertInvalidNewContextRollsBack(
                $application,
                $libraryA,
                new WorkId("work-with-inactive-book"),
                new LibraryCatalogContextInitialization(
                    new LibraryCatalogSelection($book)
                )
            );
            $this->assertInvalidNewContextRollsBack(
                $application,
                $libraryA,
                new WorkId("work-with-foreign-book"),
                new LibraryCatalogContextInitialization(
                    new LibraryCatalogSelection($foreignBook)
                )
            );
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
                            $existingWork->id(),
                            $this->initialization($library)
                        );
                } else {
                    $application->libraryItemCreation()
                        ->addWithNewWorkAndEdition(
                            $library,
                            $itemId,
                            $workId,
                            "New Work",
                            $editionId,
                            $this->initialization($library)
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

    private function initialization(
        LibraryId $libraryId
    ): LibraryCatalogContextInitialization {
        return new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection($this->seedBookType(
                $libraryId,
                "book_type.reading_book"
            ))
        );
    }

    private function seedBookType(
        LibraryId $libraryId,
        string $seedKey
    ): \Biblio\Core\Catalog\Classification\LibraryBookTypeId {
        $term = (new WpdbLibraryBookTypeRepository(
            $this->database,
            $this->tableNames
        ))->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey($seedKey)
        );

        self::assertNotNull($term);

        return $term->id();
    }

    private function seedGenre(
        LibraryId $libraryId,
        string $seedKey
    ): LibraryGenreId {
        $term = (new WpdbLibraryGenreRepository(
            $this->database,
            $this->tableNames
        ))->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey($seedKey)
        );

        self::assertNotNull($term);

        return $term->id();
    }

    private function contextCreatedEvent(
        LibraryId $libraryId,
        WorkId $workId
    ): object {
        $event = $this->database->get_row($this->database->prepare(
            "SELECT primary_entity_type, primary_entity_id, "
            . "related_entities_json, changes_json "
            . "FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE library_id = %s "
            . "AND event_key = 'library_catalog_context.created' "
            . "AND primary_entity_id = %s",
            $libraryId->value(),
            $workId->value()
        ));

        self::assertNotNull($event);

        return $event;
    }

    private function contextCreatedEventCount(
        LibraryId $libraryId,
        WorkId $workId
    ): int {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) "
            . "FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE library_id = %s "
            . "AND event_key = 'library_catalog_context.created' "
            . "AND primary_entity_id = %s",
            $libraryId->value(),
            $workId->value()
        ));
    }

    private function assertInvalidNewContextRollsBack(
        CoreApplication $application,
        LibraryId $libraryId,
        WorkId $workId,
        ?LibraryCatalogContextInitialization $classification
    ): void {
        $editionId = new EditionId("edition-" . $workId->value());
        $itemId = new ItemId("item-" . $workId->value());

        try {
            $application->libraryItemCreation()->addWithNewWorkAndEdition(
                $libraryId,
                $itemId,
                $workId,
                "Invalid Context Work",
                $editionId,
                $classification
            );
            self::fail("Invalid initial classification was accepted.");
        } catch (\Biblio\Core\Exception\ValidationException $exception) {
            self::assertSame(
                FailureReason::ValidationFailed,
                $exception->reason()
            );
        }

        self::assertNull($this->workRepository()->find($workId));
        self::assertNull($this->editionRepository()->find($editionId));
        self::assertNull($this->itemRepository()->findInLibrary(
            $itemId,
            $libraryId
        ));
        self::assertSame(
            0,
            $this->contextCreatedEventCount($libraryId, $workId)
        );
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
