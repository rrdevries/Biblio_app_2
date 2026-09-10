<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{Author,AuthorId,ContributorPosition,ContributorRole,Edition,EditionId,Work,WorkContributor,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbAuthorRepository,WpdbEditionRepository,WpdbWorkRepository};
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbLibraryMembershipRepository,WpdbLibraryRepository};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment,ManagementRole,MembershipStatus,UseAccess};
use Biblio\Core\Wishlist\{WishlistEditionUnavailable,WishlistEntryId,WishlistEntryNotAvailable,WishlistIntentConflict,WishlistRemovalReason,WishlistTargetType,WishlistWorkUnavailable};

final class WishlistPersistenceTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        if (!function_exists("wp_delete_user")) {
            require_once ABSPATH . "wp-admin/includes/user.php";
        }
        foreach ($this->userIds as $userId) {
            wp_delete_user($userId);
        }
        parent::tearDown();
    }

    public function testPersonalWishlistSupportsBothTargetsRefinementAndIdempotence(): void
    {
        $userId = $this->createUser("wishlist-owner");
        $this->seedCatalog();
        wp_set_current_user($userId);
        $application = (new ProductionComposition($this->database))->application();

        $created = $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));
        $duplicate = $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));

        self::assertTrue($created->wasCreated());
        self::assertFalse($duplicate->wasCreated());
        self::assertTrue($created->entry()->id()->equals($duplicate->entry()->id()));
        self::assertNull($created->entry()->editionId());
        self::assertSame(WishlistTargetType::WorkOnly, $created->entry()->targetType());

        try {
            $application->wishlistAdd()->addEdition(
                new WorkId("work-1"),
                new EditionId("edition-1a")
            );
            self::fail("Edition target was silently mixed with Work-only intent.");
        } catch (WishlistIntentConflict) {
            self::addToAssertionCount(1);
        }

        $refined = $application->wishlistRefine()->refineToEdition(
            new WorkId("work-1"),
            new EditionId("edition-1a")
        );
        self::assertTrue($refined->wasRefined());
        self::assertTrue($created->entry()->id()->equals($refined->entry()->id()));
        self::assertEquals(
            $created->entry()->createdAt(),
            $refined->entry()->createdAt()
        );
        self::assertGreaterThanOrEqual(
            $refined->entry()->createdAt(),
            $refined->entry()->updatedAt()
        );

        $sameEdition = $application->wishlistAdd()->addEdition(
            new WorkId("work-1"),
            new EditionId("edition-1a")
        );
        self::assertFalse($sameEdition->wasCreated());
        self::assertTrue($refined->entry()->id()->equals($sameEdition->entry()->id()));

        $otherEdition = $application->wishlistAdd()->addEdition(
            new WorkId("work-1"),
            new EditionId("edition-1b")
        );
        self::assertTrue($otherEdition->wasCreated());
        self::assertSame(2, $this->tableCount($this->tableNames->wishlistEntries()));

        try {
            $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));
            self::fail("Edition-specific intent was silently collapsed.");
        } catch (WishlistIntentConflict) {
            self::addToAssertionCount(1);
        }

        $queriesBefore = $this->database->num_queries;
        $views = $application->myWishlist()->get();
        self::assertSame(2, $this->database->num_queries - $queriesBefore);
        self::assertCount(2, $views);
        self::assertSame("Edition 1B", $views[0]->displayTitle());
        self::assertSame("Author One", $views[0]->authors()[0]->displayName());
        self::assertSame("edition-1b", $views[0]->editionId()?->value());
        self::assertSame(WishlistTargetType::EditionSpecific, $views[0]->targetType());

        $application->wishlistRemove()->remove(
            $otherEdition->entry()->id(),
            WishlistRemovalReason::Fulfilled
        );
        self::assertSame(1, $this->tableCount($this->tableNames->wishlistEntries()));
        self::assertSame(1, $this->tableCount($this->tableNames->wishlistEntryHistory()));
        self::assertSame(1, $this->tableCount($this->tableNames->wishlistWorkStates()));
        $application->wishlistRemove()->remove($refined->entry()->id());
        self::assertSame(0, $this->tableCount($this->tableNames->wishlistEntries()));
        self::assertSame(2, $this->tableCount($this->tableNames->wishlistEntryHistory()));
        self::assertSame(0, $this->tableCount($this->tableNames->wishlistWorkStates()));
        self::assertSame("fulfilled", $this->historyReason(
            $otherEdition->entry()->id()
        ));
        self::assertSame("removed", $this->historyReason(
            $refined->entry()->id()
        ));
        try {
            $application->wishlistRemove()->remove($refined->entry()->id());
            self::fail("A second Wishlist removal did not fail closed.");
        } catch (WishlistEntryNotAvailable) {
            self::addToAssertionCount(1);
        }

        $readded = $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));
        self::assertTrue($readded->wasCreated());
        self::assertFalse($readded->entry()->id()->equals($refined->entry()->id()));
        self::assertSame(1, $this->tableCount($this->tableNames->wishlistEntries()));
        self::assertSame(2, $this->tableCount($this->tableNames->wishlistEntryHistory()));
    }

    public function testWishlistIsOwnerScopedAndIndependentOfLibrariesAndOtherDomains(): void
    {
        $owner = $this->createUser("wishlist-owner-a");
        $other = $this->createUser("wishlist-owner-b");
        $this->seedCatalog();
        $libraries = new WpdbLibraryRepository($this->database, $this->tableNames);
        $memberships = new WpdbLibraryMembershipRepository($this->database, $this->tableNames);
        foreach (["wishlist-library-a", "wishlist-library-b"] as $libraryValue) {
            $libraryId = new LibraryId($libraryValue);
            $libraries->add(Library::privateLibrary($libraryId));
            $memberships->add(new LibraryMembershipAssignment(
                $libraryId,
                new UserId((string) $owner),
                LibraryMembership::owner()
            ));
        }
        $memberships->add(new LibraryMembershipAssignment(
            new LibraryId("wishlist-library-a"),
            new UserId((string) $other),
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Direct,
                MembershipStatus::Active
            )
        ));
        $application = (new ProductionComposition($this->database))->application();

        wp_set_current_user($owner);
        $entry = $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));
        wp_set_current_user($other);

        self::assertSame([], $application->myWishlist()->get());
        try {
            $application->wishlistRemove()->remove($entry->entry()->id());
            self::fail("A different user removed the owner's Wishlist Entry.");
        } catch (WishlistEntryNotAvailable) {
            self::addToAssertionCount(1);
        }

        $otherEntry = $application->wishlistAdd()->addWorkOnly(new WorkId("work-1"));
        self::assertFalse($entry->entry()->id()->equals($otherEntry->entry()->id()));
        self::assertSame(2, $this->tableCount($this->tableNames->wishlistEntries()));
        self::assertSame(0, $this->tableCount($this->tableNames->items()));
        self::assertSame(0, $this->tableCount($this->tableNames->nextReadingEntries()));
        self::assertSame(0, $this->tableCount($this->tableNames->collections()));

        wp_set_current_user($owner);
        self::assertCount(1, $application->myWishlist()->get());

        foreach ([
            $this->tableNames->wishlistEntries(),
            $this->tableNames->wishlistEntryHistory(),
            $this->tableNames->wishlistWorkStates(),
        ] as $table) {
            $columns = $this->database->get_col($this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
                DB_NAME,
                $table
            ));
            self::assertNotContains("library_id", $columns);
            self::assertNotContains("item_id", $columns);
        }
    }

    public function testWishlistRejectsUnknownWorkAndEditionWorkMismatch(): void
    {
        $userId = $this->createUser("wishlist-validation");
        $this->seedCatalog();
        wp_set_current_user($userId);
        $application = (new ProductionComposition($this->database))->application();

        try {
            $application->wishlistAdd()->addWorkOnly(new WorkId("missing"));
            self::fail("Unknown Work was accepted.");
        } catch (WishlistWorkUnavailable) {
            self::addToAssertionCount(1);
        }

        try {
            $application->wishlistAdd()->addEdition(
                new WorkId("work-2"),
                new EditionId("edition-1a")
            );
            self::fail("Edition from another Work was accepted.");
        } catch (WishlistEditionUnavailable) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->tableCount($this->tableNames->wishlistEntries()));
        self::assertSame(0, $this->tableCount($this->tableNames->wishlistWorkStates()));
    }

    private function createUser(string $login): int
    {
        $userId = wp_create_user($login, "integration-test-only");
        self::assertIsInt($userId);
        $this->userIds[] = $userId;
        return $userId;
    }

    private function seedCatalog(): void
    {
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $works->add(new Work(new WorkId("work-1"), "Work One"));
        $works->add(new Work(new WorkId("work-2"), "Work Two"));
        $editions->add(new Edition(new EditionId("edition-1a"), new WorkId("work-1"), "Edition 1A"));
        $editions->add(new Edition(new EditionId("edition-1b"), new WorkId("work-1"), "Edition 1B"));
        $authors->save(new Author(new AuthorId("author-1"), "Author One"));
        $authors->addContributor(new WorkContributor(
            new WorkId("work-1"),
            new AuthorId("author-1"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function historyReason(WishlistEntryId $entryId): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT removal_reason FROM `{$this->tableNames->wishlistEntryHistory()}` "
                . "WHERE wishlist_entry_id=%s",
            $entryId->value()
        ));
    }
}
