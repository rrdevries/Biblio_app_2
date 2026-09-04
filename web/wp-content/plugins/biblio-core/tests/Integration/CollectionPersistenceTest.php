<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Catalog\{Edition,EditionId,Item,ItemArchiveReason,ItemId,ItemStatus,Work,WorkId};
use Biblio\Core\Collections\{CollectionDescription,CollectionId,CollectionItemPosition,CollectionMembership,CollectionMembershipEndReason,CollectionMembershipId,CollectionName,CollectionNameNormalizer,CollectionPosition,CollectionStatus,LibraryCollection};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbCollectionRepository,WpdbEditionRepository,WpdbItemRepository,WpdbLibraryMembershipRepository,WpdbLibraryRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\{Library,LibraryId};
use DateTimeImmutable;
use WP_Error;

final class CollectionPersistenceTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $users = [];

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        foreach ($this->users as $id) {
            $this->database->delete($this->database->usermeta, ['user_id' => $id]);
            $this->database->delete($this->database->users, ['ID' => $id]);
        }
        parent::tearDown();
    }

    public function testProductionFlowsPreserveOrderingArchiveHistoryAndCollectionTimestampBoundary(): void
    {
        $owner = $this->createWordPressUser('collection-owner');
        $libraryId = new LibraryId('library-a');
        $this->createOwnedLibrary($libraryId, $owner);
        [$itemA, $itemB] = $this->addItems($libraryId, 'a', 'b');
        wp_set_current_user($owner);
        $application = (new ProductionComposition($this->database))->application();

        $first = $application->libraryCollectionManagement()->create(
            $libraryId,
            new CollectionName('Favorieten'),
            new CollectionDescription('Handmatig samengesteld')
        );
        $second = $application->libraryCollectionManagement()->create(
            $libraryId,
            new CollectionName('Vakantie')
        );
        $first = $application->libraryCollectionManagement()->saveItems(
            $libraryId,
            $first->id(),
            $first->version(),
            [$itemB->id(), $itemA->id()]
        );
        $second = $application->libraryCollectionManagement()->addItem(
            $libraryId,
            $second->id(),
            $second->version(),
            $itemA->id()
        );

        $queries = $application->libraryCollections();
        self::assertSame(
            [$itemB->id()->value(), $itemA->id()->value()],
            array_map(static fn (ItemId $id): string => $id->value(), $queries->activeItemsForCollections($libraryId, [$first->id()])[$first->id()->value()])
        );
        self::assertSame(2, $queries->activeCounts($libraryId, [$first->id()])[$first->id()->value()]);
        self::assertEqualsCanonicalizing(
            [$first->id()->value(), $second->id()->value()],
            array_map(static fn (CollectionId $id): string => $id->value(), $queries->activeCollectionsForItems($libraryId, [$itemA->id()])[$itemA->id()->value()])
        );

        $second = $application->libraryCollectionManagement()->archive($libraryId, $second->id(), $second->version());
        self::assertSame(CollectionStatus::Archived, $second->status());
        self::assertSame([$first->id()->value()], array_map(static fn (CollectionId $id): string => $id->value(), $queries->activeCollectionsForItems($libraryId, [$itemA->id()])[$itemA->id()->value()]));
        $second = $application->libraryCollectionManagement()->restore($libraryId, $second->id(), $second->version());
        self::assertSame(1, $queries->activeCounts($libraryId, [$second->id()])[$second->id()->value()]);

        $beforeItemArchive = $queries->collections($libraryId, [$first->id()])[$first->id()->value()];
        self::assertNotNull($beforeItemArchive);
        $archived = $application->libraryItemArchiveManagement()->archive($libraryId, $itemA->id(), ItemArchiveReason::Sold, $itemA->version());
        self::assertSame(ItemStatus::Archived, $archived->status());
        self::assertSame(1, $queries->activeCounts($libraryId, [$first->id()])[$first->id()->value()]);
        self::assertSame([], $queries->activeCollectionsForItems($libraryId, [$itemA->id()])[$itemA->id()->value()]);
        self::assertEqualsCanonicalizing(
            [$first->id()->value(), $second->id()->value()],
            array_map(static fn (CollectionId $id): string => $id->value(), $queries->previousCollectionsForArchivedItems($libraryId, [$itemA->id()])[$itemA->id()->value()])
        );
        $afterItemArchive = $queries->collections($libraryId, [$first->id()])[$first->id()->value()];
        self::assertNotNull($afterItemArchive);
        self::assertSame($beforeItemArchive->version()->value(), $afterItemArchive->version()->value());
        self::assertEquals($beforeItemArchive->updatedAt(), $afterItemArchive->updatedAt());

        $restored = $application->libraryItemArchiveManagement()->restore($libraryId, $itemA->id(), $archived->version());
        self::assertSame(ItemStatus::Active, $restored->status());
        self::assertSame([], $queries->activeCollectionsForItems($libraryId, [$itemA->id()])[$itemA->id()->value()]);
        self::assertSame([], $queries->previousCollectionsForArchivedItems($libraryId, [$itemA->id()])[$itemA->id()->value()]);

        $first = $application->libraryCollectionManagement()->addItem($libraryId, $first->id(), $first->version(), $itemA->id());
        self::assertSame([$itemB->id()->value(), $itemA->id()->value()], array_map(static fn (ItemId $id): string => $id->value(), $queries->activeItemsForCollections($libraryId, [$first->id()])[$first->id()->value()]));
        self::assertSame(3, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->collectionMemberships()}` WHERE item_id='item-a'"));
    }

    public function testPersistenceEnforcesDuplicateDanglingAndCrossLibraryMembershipIsolation(): void
    {
        $libraryA = new LibraryId('library-a');
        $libraryB = new LibraryId('library-b');
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(Library::privateLibrary($libraryA));
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(Library::privateLibrary($libraryB));
        [$itemA] = $this->addItems($libraryA, 'a');
        [$itemB] = $this->addItems($libraryB, 'b');
        $repository = new WpdbCollectionRepository($this->database, $this->tableNames);
        $name = new CollectionName('A');
        $collection = LibraryCollection::create(new CollectionId('collection-a'), $libraryA, $name, (new CollectionNameNormalizer())->normalize($name), null, new CollectionPosition(1), new DateTimeImmutable('2026-09-04 10:00:00.000001+00:00'));
        $repository->add($collection);
        $repository->addMembership(CollectionMembership::active(new CollectionMembershipId('membership-a'), $libraryA, $collection->id(), $itemA->id(), new CollectionItemPosition(1), new DateTimeImmutable('2026-09-04 10:01:00.000002+00:00')));

        $previous = $this->database->suppress_errors(true);
        try {
            foreach ([
                ['membership-duplicate', $libraryA->value(), $collection->id()->value(), $itemA->id()->value()],
                ['membership-cross-item', $libraryA->value(), $collection->id()->value(), $itemB->id()->value()],
                ['membership-cross-collection', $libraryB->value(), $collection->id()->value(), $itemB->id()->value()],
                ['membership-dangling', $libraryA->value(), 'missing', $itemA->id()->value()],
            ] as [$membershipId, $libraryId, $collectionId, $itemId]) {
                self::assertFalse($this->database->insert($this->tableNames->collectionMemberships(), [
                    'library_id' => $libraryId, 'membership_id' => $membershipId,
                    'collection_id' => $collectionId, 'item_id' => $itemId,
                    'membership_status' => 'active', 'item_position' => 2,
                    'added_at' => '2026-09-04 10:02:00.000003',
                ]));
            }
        } finally {
            $this->database->suppress_errors($previous);
        }

        $repository->replaceMembership(CollectionMembership::active(new CollectionMembershipId('membership-a'), $libraryA, $collection->id(), $itemA->id(), new CollectionItemPosition(1), new DateTimeImmutable('2026-09-04 10:01:00.000002+00:00'))->deactivate(CollectionMembershipEndReason::Removed, new DateTimeImmutable('2026-09-04 10:03:00.000004+00:00')));
        $repository->addMembership(CollectionMembership::active(new CollectionMembershipId('membership-new-period'), $libraryA, $collection->id(), $itemA->id(), new CollectionItemPosition(1), new DateTimeImmutable('2026-09-04 10:04:00.000005+00:00')));
        self::assertCount(2, $this->database->get_results("SELECT membership_id FROM `{$this->tableNames->collectionMemberships()}` WHERE library_id='library-a' AND item_id='item-a'"));
    }

    /** @return list<Item> */
    private function addItems(LibraryId $libraryId, string ...$suffixes): array
    {
        $items = [];
        foreach ($suffixes as $suffix) {
            $work = new Work(new WorkId("work-{$libraryId->value()}-{$suffix}"), "Work {$suffix}");
            (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
            $edition = new Edition(new EditionId("edition-{$libraryId->value()}-{$suffix}"), $work->id());
            (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
            $item = Item::active(new ItemId("item-{$suffix}"), $libraryId, $edition->id());
            (new WpdbItemRepository($this->database, $this->tableNames))->add($item);
            $items[] = $item;
        }
        return $items;
    }

    private function createOwnedLibrary(LibraryId $libraryId, int $owner): void
    {
        (new CreateLibraryService(new WpdbLibraryRepository($this->database, $this->tableNames), new WpdbLibraryMembershipRepository($this->database, $this->tableNames), $this->classificationSeedEvolution(), new WpdbTransactionManager($this->database)))->create(Library::privateLibrary($libraryId), new UserId((string) $owner));
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user(['user_login' => $login . '-' . bin2hex(random_bytes(4)), 'user_pass' => 'integration-test-only', 'user_email' => $login . '-' . bin2hex(random_bytes(4)) . '@example.invalid']);
        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);
        $this->users[] = $result;
        return $result;
    }
}
