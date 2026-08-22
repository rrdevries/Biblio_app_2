<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Classification\ClassificationTermActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextStale;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibrarySubjectRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\WordPressActivityEventFactory;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAuthenticatedUser;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use JsonException;
use RuntimeException;
use WP_Error;

final class FailingClassificationActivityAppender implements
    ActivityEventAppender
{
    public function append(ActivityEvent $event): void
    {
        throw new PersistenceException("Deliberate audit failure.");
    }
}

final class ClassificationManagementApplicationTest extends
    PersistenceIntegrationTestCase
{
    public function testLegacyContextCreationIsAuthorizedAndRepresentationScoped(): void
    {
        $ownerId = $this->createWordPressUser("classification-owner");
        $managerId = $this->createWordPressUser("classification-manager");
        $itemManagerId = $this->createWordPressUser("item-only-manager");
        $memberId = $this->createWordPressUser("classification-member");
        $inactiveId = $this->createWordPressUser("classification-inactive");
        $libraryId = $this->createLibrary("library-a", $ownerId);
        $workId = $this->addRepresentedWork($libraryId, "work-a");
        $unrepresented = new WorkId("work-central-only");
        $this->works()->add(new Work($unrepresented, "Central only"));
        $this->addMembership(
            $libraryId,
            $managerId,
            ManagementRole::Manager,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $this->addMembership(
            $libraryId,
            $itemManagerId,
            ManagementRole::Manager,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD
            )
        );
        $this->addMembership(
            $libraryId,
            $memberId,
            ManagementRole::Member,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $this->addMembership(
            $libraryId,
            $inactiveId,
            ManagementRole::Manager,
            MembershipStatus::Inactive,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );
        $selection = $this->seedSelection($libraryId);
        $application = (new ProductionComposition($this->database))
            ->application();

        wp_set_current_user($managerId);
        $created = $application->catalogContextCreation()
            ->createForRepresentedWork($libraryId, $workId, $selection);
        self::assertSame(1, $created->version()->value());
        self::assertSame(1, $this->eventCount($libraryId));
        $same = $application->catalogContextCreation()
            ->createForRepresentedWork($libraryId, $workId, $selection);
        self::assertSame(1, $same->version()->value());
        self::assertSame(1, $this->eventCount($libraryId));

        try {
            $application->catalogContextCreation()->createForRepresentedWork(
                $libraryId,
                $workId,
                new LibraryCatalogSelection($this->seedBookType(
                    $libraryId,
                    "book_type.cookbook"
                ))
            );
            self::fail("Different existing context was silently reused.");
        } catch (LibraryCatalogContextAlreadyExists) {
            self::assertSame(1, $this->eventCount($libraryId));
        }

        wp_set_current_user($ownerId);
        try {
            $application->catalogContextCreation()->createForRepresentedWork(
                $libraryId,
                $unrepresented,
                $selection
            );
            self::fail("Unrepresented central Work received a context.");
        } catch (ValidationException) {
            self::assertNull($this->contexts()->find(
                $libraryId,
                $unrepresented
            ));
        }

        foreach ([$itemManagerId, $memberId, $inactiveId] as $deniedId) {
            wp_set_current_user($deniedId);

            try {
                $application->catalogContextCreation()
                    ->createForRepresentedWork(
                        $libraryId,
                        new WorkId("not-disclosed"),
                        $selection
                    );
                self::fail("Unauthorized context management was accepted.");
            } catch (AuthorizationException) {
                self::assertSame(1, $this->eventCount($libraryId));
            }
        }
    }

    /** @throws JsonException */
    public function testContextSaveSupportsNoOpStaleNoOpAndRetainedInactiveTerms(): void
    {
        $ownerId = $this->createWordPressUser("context-save-owner");
        $libraryId = $this->createLibrary("library-a", $ownerId);
        $workId = $this->addRepresentedWork($libraryId, "work-a");
        $bookA = $this->seedBookType($libraryId, "book_type.reading_book");
        $bookB = $this->seedBookType($libraryId, "book_type.cookbook");
        $genreA = $this->seedGenre($libraryId, "genre.fantasy");
        $genreB = $this->seedGenre($libraryId, "genre.thriller");
        $subject = new LibrarySubjectId("subject-history");
        wp_set_current_user($ownerId);
        $application = (new ProductionComposition($this->database))
            ->application();
        $application->subjectManagement()->create(
            $libraryId,
            $subject,
            new ClassificationTermName("Geschiedenis")
        );
        $initial = new LibraryCatalogSelection(
            $bookA,
            [$genreA],
            [$subject]
        );
        $application->catalogContextCreation()->createForRepresentedWork(
            $libraryId,
            $workId,
            $initial
        );
        $eventsBeforeSave = $this->eventCount($libraryId);
        $this->bookTypes()->changeStatus(
            $libraryId,
            $bookA,
            ClassificationTermStatus::Inactive
        );
        $this->genres()->changeStatus(
            $libraryId,
            $genreA,
            ClassificationTermStatus::Inactive
        );
        $this->subjects()->changeStatus(
            $libraryId,
            $subject,
            ClassificationTermStatus::Inactive
        );
        $desired = new LibraryCatalogSelection(
            $bookA,
            [$genreA, $genreB],
            [$subject]
        );
        $saved = $application->catalogContextManagement()->save(
            $libraryId,
            $workId,
            new LibraryCatalogContextVersion(1),
            $desired,
            false
        );
        self::assertSame(2, $saved->version()->value());
        self::assertSame($eventsBeforeSave + 1, $this->eventCount($libraryId));

        $noOp = $application->catalogContextManagement()->save(
            $libraryId,
            $workId,
            new LibraryCatalogContextVersion(2),
            new LibraryCatalogSelection(
                $bookA,
                [$genreB, $genreA],
                [$subject]
            ),
            false
        );
        $staleNoOp = $application->catalogContextManagement()->save(
            $libraryId,
            $workId,
            new LibraryCatalogContextVersion(1),
            $desired,
            false
        );
        self::assertSame(2, $noOp->version()->value());
        self::assertSame(2, $staleNoOp->version()->value());
        self::assertSame($eventsBeforeSave + 1, $this->eventCount($libraryId));

        try {
            $application->catalogContextManagement()->save(
                $libraryId,
                $workId,
                new LibraryCatalogContextVersion(1),
                new LibraryCatalogSelection($bookB),
                true
            );
            self::fail("Stale divergent save was accepted.");
        } catch (LibraryCatalogContextStale $exception) {
            self::assertSame(2, $exception
                ->currentContext()->version()->value());
        }

        try {
            $application->catalogContextManagement()->save(
                $libraryId,
                $workId,
                new LibraryCatalogContextVersion(2),
                new LibraryCatalogSelection($bookB),
                false
            );
            self::fail("Book Type changed without confirmation.");
        } catch (ValidationException) {
            self::assertSame(2, $this->contexts()
                ->find($libraryId, $workId)?->version()->value());
        }

        $changedBook = $application->catalogContextManagement()->save(
            $libraryId,
            $workId,
            new LibraryCatalogContextVersion(2),
            new LibraryCatalogSelection($bookB),
            true
        );
        self::assertSame(3, $changedBook->version()->value());
        $row = $this->latestEvent($libraryId);
        self::assertSame(
            "library_catalog_context.updated",
            $row->event_key
        );
        self::assertSame("LibraryCatalogContext", $row->primary_entity_type);
        self::assertSame((string) $ownerId, $row->actor_user_id);
        self::assertSame(
            "Actor context-save-owner",
            $row->actor_display_name
        );
        self::assertSame("core.classification", $row->event_source);
        $changes = json_decode(
            (string) $row->changes_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame($bookA->value(), $changes[0]["old_value"]["id"]);
        self::assertSame("Leesboek", $changes[0]["old_value"]["label"]);
        self::assertSame($bookB->value(), $changes[0]["new_value"]["id"]);
        self::assertSame("Kookboek", $changes[0]["new_value"]["label"]);
    }

    public function testSeparateTermServicesEnforceLifecycleConflictsAndLastBookConfirmation(): void
    {
        $ownerId = $this->createWordPressUser("term-owner");
        $libraryId = $this->createLibrary("library-a", $ownerId);
        wp_set_current_user($ownerId);
        $application = (new ProductionComposition($this->database))
            ->application();
        $genreA = new LibraryGenreId("genre-custom-a");
        $genreB = new LibraryGenreId("genre-custom-b");
        $subject = new LibrarySubjectId("subject-custom");
        $customBook = new LibraryBookTypeId("book-custom");

        $application->bookTypeManagement()->create(
            $libraryId,
            $customBook,
            new ClassificationTermName("Eigen Boeksoort")
        );
        $application->bookTypeManagement()->rename(
            $libraryId,
            $customBook,
            new ClassificationTermName("Nieuwe Boeksoort")
        );
        $application->bookTypeManagement()->deactivate(
            $libraryId,
            $customBook,
            false
        );
        $bookAgain = $application->bookTypeManagement()->reactivate(
            $libraryId,
            $customBook
        );
        self::assertSame($customBook->value(), $bookAgain->id()->value());

        $created = $application->genreManagement()->create(
            $libraryId,
            $genreA,
            new ClassificationTermName("Eigen Genre")
        );
        $renamed = $application->genreManagement()->rename(
            $libraryId,
            $genreA,
            new ClassificationTermName("Nieuw Genre")
        );
        $equivalentNoOp = $application->genreManagement()->rename(
            $libraryId,
            $genreA,
            new ClassificationTermName("NIEUW-GENRE")
        );
        self::assertSame("Eigen Genre", $created->name()->value());
        self::assertSame("Nieuw Genre", $renamed->name()->value());
        self::assertSame("Nieuw Genre", $equivalentNoOp->name()->value());
        $application->genreManagement()->deactivate($libraryId, $genreA);
        $application->genreManagement()->deactivate($libraryId, $genreA);
        $reactivated = $application->genreManagement()->reactivate(
            $libraryId,
            $genreA
        );
        self::assertSame($genreA->value(), $reactivated->id()->value());
        self::assertSame(
            ClassificationTermStatus::Active,
            $reactivated->status()
        );

        $application->genreManagement()->create(
            $libraryId,
            $genreB,
            new ClassificationTermName("Ander Genre")
        );
        $eventsBeforeConflict = $this->eventCount($libraryId);

        try {
            $application->genreManagement()->rename(
                $libraryId,
                $genreB,
                new ClassificationTermName("NIEUW GENRE")
            );
            self::fail("Conflicting normalized rename was accepted.");
        } catch (ClassificationTermConflict) {
            self::assertSame(
                "Ander Genre",
                $this->genres()->find($libraryId, $genreB)?->name()->value()
            );
            self::assertSame(
                $eventsBeforeConflict,
                $this->eventCount($libraryId)
            );
        }

        $application->subjectManagement()->create(
            $libraryId,
            $subject,
            new ClassificationTermName("Onderwerp")
        );
        $application->subjectManagement()->rename(
            $libraryId,
            $subject,
            new ClassificationTermName("Hernoemd Onderwerp")
        );
        $application->subjectManagement()->deactivate($libraryId, $subject);
        $subjectAgain = $application->subjectManagement()->reactivate(
            $libraryId,
            $subject
        );
        self::assertSame($subject->value(), $subjectAgain->id()->value());

        $lastBook = $this->seedBookType(
            $libraryId,
            "book_type.reading_book"
        );
        foreach ($this->bookTypeRows($libraryId) as $bookTypeId) {
            if ($bookTypeId !== $lastBook->value()) {
                $this->bookTypes()->changeStatus(
                    $libraryId,
                    new LibraryBookTypeId($bookTypeId),
                    ClassificationTermStatus::Inactive
                );
            }
        }
        $eventsBeforeLastBook = $this->eventCount($libraryId);

        try {
            $application->bookTypeManagement()->deactivate(
                $libraryId,
                $lastBook,
                false
            );
            self::fail("Last Book Type deactivated without confirmation.");
        } catch (ValidationException) {
            self::assertSame(1, $this->bookTypes()->countActive($libraryId));
            self::assertSame(
                $eventsBeforeLastBook,
                $this->eventCount($libraryId)
            );
        }

        $application->bookTypeManagement()->deactivate(
            $libraryId,
            $lastBook,
            true
        );
        self::assertSame(0, $this->bookTypes()->countActive($libraryId));
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1003)->isHealthy());
        self::assertSame(
            $eventsBeforeLastBook + 1,
            $this->eventCount($libraryId)
        );
    }

    public function testAuditFailureRollsBackTermAndContextMutation(): void
    {
        $ownerId = $this->createWordPressUser("audit-rollback-owner");
        $libraryId = $this->createLibrary("library-a", $ownerId);
        $workId = $this->addRepresentedWork($libraryId, "work-a");
        wp_set_current_user($ownerId);
        $production = (new ProductionComposition($this->database))
            ->application();
        $initial = $this->seedSelection($libraryId);
        $production->catalogContextCreation()->createForRepresentedWork(
            $libraryId,
            $workId,
            $initial
        );
        $failingAppender = new FailingClassificationActivityAppender();
        $factory = new WordPressActivityEventFactory(
            new ActivityEventSource("core.classification")
        );
        $membershipRepository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $access = new LibraryAccessService(
            $membershipRepository,
            new LibraryAuthorizationPolicy()
        );
        $transactions = new WpdbTransactionManager($this->database);
        $genreId = new LibraryGenreId("genre-audit-failure");
        $genres = new WpdbLibraryGenreRepository(
            $this->database,
            $this->tableNames
        );
        $failingGenres = new ManageLibraryGenresService(
            new WordPressAuthenticatedUser(),
            $access,
            $genres,
            ClassificationNameNormalizer::create(),
            new ClassificationTermActivity($factory),
            $failingAppender,
            $transactions
        );

        try {
            $failingGenres->create(
                $libraryId,
                $genreId,
                new ClassificationTermName("Rollback Genre")
            );
            self::fail("Term mutation survived audit failure.");
        } catch (PersistenceException) {
            self::assertNull($genres->find($libraryId, $genreId));
        }

        $bookB = $this->seedBookType($libraryId, "book_type.cookbook");
        $contexts = $this->contexts();
        $failingSave = new SaveLibraryCatalogContextService(
            new WordPressAuthenticatedUser(),
            $access,
            $this->works(),
            $contexts,
            new LibraryCatalogSelectionResolver(
                $this->bookTypes(),
                $genres,
                $this->subjects()
            ),
            new LibraryCatalogContextActivity($factory),
            $failingAppender,
            $transactions
        );

        try {
            $failingSave->save(
                $libraryId,
                $workId,
                new LibraryCatalogContextVersion(1),
                new LibraryCatalogSelection($bookB),
                true
            );
            self::fail("Context mutation survived audit failure.");
        } catch (PersistenceException) {
            $stored = $contexts->find($libraryId, $workId);
            self::assertNotNull($stored);
            self::assertSame(1, $stored->version()->value());
            self::assertTrue($stored->classification()->equals($initial));
        }
    }

    private function createLibrary(string $value, int $ownerId): LibraryId
    {
        $libraryId = new LibraryId($value);
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
            new UserId((string) $ownerId)
        );

        return $libraryId;
    }

    private function addRepresentedWork(
        LibraryId $libraryId,
        string $workValue
    ): WorkId {
        $workId = new WorkId($workValue);
        $editionId = new EditionId("edition-" . $workValue);
        $this->works()->add(new Work($workId, "Title {$workValue}"));
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add(new Edition($editionId, $workId));
        (new WpdbItemRepository(
            $this->database,
            $this->tableNames
        ))->add(Item::active(
            new ItemId("item-" . $workValue),
            $libraryId,
            $editionId
        ));

        return $workId;
    }

    private function seedSelection(
        LibraryId $libraryId
    ): LibraryCatalogSelection {
        return new LibraryCatalogSelection(
            $this->seedBookType($libraryId, "book_type.reading_book"),
            [$this->seedGenre($libraryId, "genre.fantasy")]
        );
    }

    private function seedBookType(
        LibraryId $libraryId,
        string $seedKey
    ): LibraryBookTypeId {
        $term = $this->bookTypes()->findBySeedKey(
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
        $term = $this->genres()->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey($seedKey)
        );
        self::assertNotNull($term);

        return $term->id();
    }

    private function addMembership(
        LibraryId $libraryId,
        int $userId,
        ManagementRole $role,
        MembershipStatus $status,
        AdditionalPermissions $permissions
    ): void {
        (new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        ))->add(new LibraryMembershipAssignment(
            $libraryId,
            new UserId((string) $userId),
            new LibraryMembership(
                $role,
                UseAccess::ViewOnly,
                $status,
                $permissions
            )
        ));
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
            "display_name" => "Actor {$login}",
        ]);

        if ($result instanceof WP_Error || !is_int($result)) {
            throw new RuntimeException("Could not create test user.");
        }

        return $result;
    }

    private function eventCount(LibraryId $libraryId): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE library_id = %s",
            $libraryId->value()
        ));
    }

    private function latestEvent(LibraryId $libraryId): object
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE library_id = %s ORDER BY occurred_at DESC, event_id DESC "
            . "LIMIT 1",
            $libraryId->value()
        ));
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<string> */
    private function bookTypeRows(LibraryId $libraryId): array
    {
        $values = $this->database->get_col($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` "
            . "WHERE library_id = %s",
            $libraryId->value()
        ));

        return array_map(
            static fn (mixed $value): string => (string) $value,
            $values
        );
    }

    private function bookTypes(): WpdbLibraryBookTypeRepository
    {
        return new WpdbLibraryBookTypeRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function genres(): WpdbLibraryGenreRepository
    {
        return new WpdbLibraryGenreRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function subjects(): WpdbLibrarySubjectRepository
    {
        return new WpdbLibrarySubjectRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function contexts(): WpdbLibraryCatalogContextRepository
    {
        return new WpdbLibraryCatalogContextRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function works(): WpdbWorkRepository
    {
        return new WpdbWorkRepository($this->database, $this->tableNames);
    }
}
