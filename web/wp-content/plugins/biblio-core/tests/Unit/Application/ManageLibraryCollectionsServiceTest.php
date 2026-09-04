<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Collections\ManageLibraryCollectionsService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{EditionId,Item,ItemId,ItemRepository};
use Biblio\Core\Collections\{CollectionClock,CollectionDescription,CollectionId,CollectionIdGenerator,CollectionItemNotAvailable,CollectionMembership,CollectionMembershipConflict,CollectionMembershipEndReason,CollectionMembershipId,CollectionMembershipIdGenerator,CollectionName,CollectionNameNormalizer,CollectionNotAvailable,CollectionPosition,CollectionStale,CollectionStatus,CollectionVersion,LibraryCollection,NormalizedCollectionName,WritableCollectionRepository};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{AdditionalPermissions,LibraryId,LibraryMembership,LibraryMembershipAssignment,LibraryMembershipRepository,LibraryMutationLock,ManagementRole,MembershipStatus,UseAccess};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CollectionTestTransactions implements TransactionManager
{
    public int $calls = 0;
    public function run(callable $operation): mixed { ++$this->calls; return $operation(); }
}

final class CollectionTestClock implements CollectionClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class CollectionTestLock implements LibraryMutationLock
{
    public int $calls = 0;
    public function acquire(LibraryId $libraryId): void { ++$this->calls; }
}

final class CollectionTestCollectionIds implements CollectionIdGenerator
{
    private int $collections = 0;
    public function next(): CollectionId { return new CollectionId('collection-' . ++$this->collections); }
}

final class CollectionTestMembershipIds implements CollectionMembershipIdGenerator
{
    private int $memberships = 0;
    public function next(): CollectionMembershipId { return new CollectionMembershipId('membership-' . ++$this->memberships); }
}

final class CollectionTestItems implements ItemRepository
{
    /** @param list<Item> $items */
    public function __construct(private array $items) {}
    public function findInLibrary(ItemId $itemId, LibraryId $libraryId): ?Item
    {
        return $this->findManyInLibrary($libraryId, [$itemId])[$itemId->value()];
    }
    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $id) {
            $result[$id->value()] = null;
            foreach ($this->items as $item) {
                if ($item->id()->equals($id) && $item->libraryId()->equals($libraryId)) { $result[$id->value()] = $item; }
            }
        }
        return $result;
    }
}

final class CollectionTestMemberships implements LibraryMembershipRepository
{
    public function __construct(private LibraryMembershipAssignment $assignment) {}
    public function findFor(LibraryId $libraryId, UserId $userId): ?LibraryMembershipAssignment
    { return $this->assignment->libraryId()->equals($libraryId) && $this->assignment->userId()->equals($userId) ? $this->assignment : null; }
}

final class InMemoryCollectionRepository implements WritableCollectionRepository
{
    /** @var array<string,LibraryCollection> */ public array $collections = [];
    /** @var array<string,CollectionMembership> */ public array $memberships = [];
    public function nextPositionForUpdate(LibraryId $libraryId): CollectionPosition
    { return new CollectionPosition(count(array_filter($this->collections, fn (LibraryCollection $c): bool => $c->libraryId()->equals($libraryId) && $c->status() === CollectionStatus::Active)) + 1); }
    public function add(LibraryCollection $collection): void { $this->collections[$collection->id()->value()] = $collection; }
    public function findForUpdate(LibraryId $libraryId, CollectionId $collectionId): ?LibraryCollection
    { $value = $this->collections[$collectionId->value()] ?? null; return $value !== null && $value->libraryId()->equals($libraryId) ? $value : null; }
    public function activeByNormalizedNameForUpdate(LibraryId $libraryId, NormalizedCollectionName $name): ?LibraryCollection
    { foreach ($this->collections as $value) { if ($value->libraryId()->equals($libraryId) && $value->status() === CollectionStatus::Active && $value->normalizedName()->equals($name)) { return $value; } } return null; }
    public function activeForLibraryForUpdate(LibraryId $libraryId): array { return $this->activeForLibrary($libraryId); }
    public function replaceIfVersionMatches(LibraryCollection $replacement, CollectionVersion $expectedVersion): bool
    { $current = $this->collections[$replacement->id()->value()] ?? null; if ($current === null || !$current->version()->equals($expectedVersion)) { return false; } $this->collections[$replacement->id()->value()] = $replacement; return true; }
    public function activeMembershipsForUpdate(LibraryId $libraryId, CollectionId $collectionId): array
    { $values = array_values(array_filter($this->memberships, fn (CollectionMembership $m): bool => $m->libraryId()->equals($libraryId) && $m->collectionId()->equals($collectionId) && $m->status()->value === 'active')); usort($values, fn (CollectionMembership $a, CollectionMembership $b): int => $a->position()->value() <=> $b->position()->value()); return $values; }
    public function addMembership(CollectionMembership $membership): void { $this->memberships[$membership->id()->value()] = $membership; }
    public function replaceMembership(CollectionMembership $membership): void { $this->memberships[$membership->id()->value()] = $membership; }
    public function deactivateForArchivedItem(LibraryId $libraryId, ItemId $itemId, DateTimeImmutable $archivedAt): void
    { foreach ($this->memberships as $id => $membership) { if ($membership->libraryId()->equals($libraryId) && $membership->itemId()->equals($itemId) && $membership->status()->value === 'active') { $this->memberships[$id] = $membership->deactivate(CollectionMembershipEndReason::ItemArchived, $archivedAt); } } }
    public function activeForLibrary(LibraryId $libraryId): array
    { $values = array_values(array_filter($this->collections, fn (LibraryCollection $c): bool => $c->libraryId()->equals($libraryId) && $c->status() === CollectionStatus::Active)); usort($values, fn (LibraryCollection $a, LibraryCollection $b): int => $a->position()->value() <=> $b->position()->value()); return $values; }
    public function findManyInLibrary(LibraryId $libraryId, array $collectionIds): array { $result = []; foreach ($collectionIds as $id) { $result[$id->value()] = $this->findForUpdate($libraryId, $id); } return $result; }
    public function activeCollectionIdsForItems(LibraryId $libraryId, array $itemIds): array { return []; }
    public function activeItemIdsForCollections(LibraryId $libraryId, array $collectionIds): array { return []; }
    public function activeCountsForCollections(LibraryId $libraryId, array $collectionIds): array { return []; }
    public function previousCollectionIdsForArchivedItems(LibraryId $libraryId, array $itemIds): array { return []; }
}

final class ManageLibraryCollectionsServiceTest extends TestCase
{
    public function testOwnerCreatesEmptyCollectionsAtBottomAndNamesAreUniqueAfterNormalization(): void
    {
        [$service, $repository, , $transactions] = $this->fixture(ManagementRole::Owner);
        $first = $service->create(new LibraryId('library-a'), new CollectionName('  FAVORIETEN '));
        $second = $service->create(new LibraryId('library-a'), new CollectionName('Later'));

        self::assertSame(1, $first->position()->value());
        self::assertSame(2, $second->position()->value());
        self::assertSame([], $repository->memberships);
        self::assertSame(2, $transactions->calls);

        $this->expectException(\Biblio\Core\Collections\CollectionNameConflict::class);
        $service->create(new LibraryId('library-a'), new CollectionName("favorieten"));
    }

    public function testMembershipOrderingDuplicateAndForeignItemRulesAreEnforced(): void
    {
        [$service, $repository] = $this->fixture(ManagementRole::Owner);
        $collection = $service->create(new LibraryId('library-a'), new CollectionName('Favorieten'));
        $collection = $service->saveItems(new LibraryId('library-a'), $collection->id(), $collection->version(), [new ItemId('item-b'), new ItemId('item-a')]);
        self::assertSame(['item-b', 'item-a'], array_map(fn (CollectionMembership $m): string => $m->itemId()->value(), $repository->activeMembershipsForUpdate(new LibraryId('library-a'), $collection->id())));
        $collection = $service->saveItems(new LibraryId('library-a'), $collection->id(), $collection->version(), [new ItemId('item-a')]);
        self::assertSame(['item-a'], array_map(fn (CollectionMembership $m): string => $m->itemId()->value(), $repository->activeMembershipsForUpdate(new LibraryId('library-a'), $collection->id())));
        self::assertCount(2, $repository->memberships);

        foreach ([[new ItemId('item-a'), new ItemId('item-a')], [new ItemId('foreign')], [new ItemId('archived')]] as $ids) {
            try { $service->saveItems(new LibraryId('library-a'), $collection->id(), $collection->version(), $ids); self::fail('Invalid membership draft accepted.'); }
            catch (CollectionMembershipConflict|CollectionItemNotAvailable) {}
        }
    }

    public function testArchiveRestorePreservesMembershipAndArchivedCollectionIsReadOnly(): void
    {
        [$service, $repository] = $this->fixture(ManagementRole::Owner);
        $collection = $service->create(new LibraryId('library-a'), new CollectionName('Favorieten'));
        $collection = $service->addItem(new LibraryId('library-a'), $collection->id(), $collection->version(), new ItemId('item-a'));
        $archived = $service->archive(new LibraryId('library-a'), $collection->id(), $collection->version());
        self::assertSame(CollectionStatus::Archived, $archived->status());
        self::assertCount(1, $repository->memberships);
        try { $service->addItem(new LibraryId('library-a'), $archived->id(), $archived->version(), new ItemId('item-b')); self::fail('Archived Collection was writable.'); }
        catch (\Biblio\Core\Collections\CollectionTransitionUnavailable) {}
        $restored = $service->restore(new LibraryId('library-a'), $archived->id(), $archived->version());
        self::assertSame(CollectionStatus::Active, $restored->status());
        self::assertCount(1, $repository->memberships);
    }

    public function testCollectionReorderIsCompleteDeterministicAndVersioned(): void
    {
        [$service] = $this->fixture(ManagementRole::Owner);
        $first = $service->create(new LibraryId('library-a'), new CollectionName('Eerste'));
        $second = $service->create(new LibraryId('library-a'), new CollectionName('Tweede'));

        $reordered = $service->reorderCollections(new LibraryId('library-a'), [$second->id(), $first->id()]);
        self::assertSame([$second->id()->value(), $first->id()->value()], array_map(static fn (LibraryCollection $collection): string => $collection->id()->value(), $reordered));
        self::assertSame([1, 2], array_map(static fn (LibraryCollection $collection): int => $collection->position()->value(), $reordered));
        self::assertSame([2, 2], array_map(static fn (LibraryCollection $collection): int => $collection->version()->value(), $reordered));

        $this->expectException(CollectionNotAvailable::class);
        $service->reorderCollections(new LibraryId('library-a'), [$first->id()]);
    }

    public function testStaleAndForeignCollectionAreNonEnumeratingTypedFailures(): void
    {
        [$service] = $this->fixture(ManagementRole::Owner);
        $collection = $service->create(new LibraryId('library-a'), new CollectionName('Favorieten'));
        try { $service->archive(new LibraryId('library-a'), $collection->id(), new CollectionVersion(9)); self::fail('Stale Collection accepted.'); }
        catch (CollectionStale $exception) { self::assertSame(1, $exception->current()->version()->value()); }
        $this->expectException(CollectionNotAvailable::class);
        $service->archive(new LibraryId('library-a'), new CollectionId('foreign'), CollectionVersion::initial());
    }

    public function testManagerNeedsCanonicalPermissionAndMemberCannotReachTransaction(): void
    {
        [$manager] = $this->fixture(ManagementRole::Manager, AdditionalPermissions::fromValues(AdditionalPermissions::COLLECTIONS_MANAGE));
        self::assertSame('collection-1', $manager->create(new LibraryId('library-a'), new CollectionName('Mag'))->id()->value());
        [$member, , , $transactions] = $this->fixture(ManagementRole::Member, AdditionalPermissions::fromValues(AdditionalPermissions::COLLECTIONS_MANAGE));
        try { $member->create(new LibraryId('library-a'), new CollectionName('Niet')); self::fail('Member was authorized.'); }
        catch (CollectionNotAvailable $exception) { self::assertSame('collection_not_available', $exception->reason()->value); }
        self::assertSame(0, $transactions->calls);
    }

    /** @return array{ManageLibraryCollectionsService,InMemoryCollectionRepository,CollectionTestLock,CollectionTestTransactions} */
    private function fixture(ManagementRole $role, ?AdditionalPermissions $permissions = null): array
    {
        $libraryId = new LibraryId('library-a'); $userId = new UserId('user-a');
        $membership = new LibraryMembership($role, $role === ManagementRole::Owner ? UseAccess::Direct : UseAccess::ViewOnly, MembershipStatus::Active, $permissions);
        $repository = new InMemoryCollectionRepository(); $lock = new CollectionTestLock(); $transactions = new CollectionTestTransactions();
        $items = [Item::active(new ItemId('item-a'), $libraryId, new EditionId('edition-a')), Item::active(new ItemId('item-b'), $libraryId, new EditionId('edition-b')), Item::active(new ItemId('archived'), $libraryId, new EditionId('edition-archived'))->archive(), Item::active(new ItemId('foreign'), new LibraryId('library-b'), new EditionId('edition-c'))];
        return [new ManageLibraryCollectionsService(new ControllableAuthenticatedUser($userId), new LibraryAccessService(new CollectionTestMemberships(new LibraryMembershipAssignment($libraryId, $userId, $membership)), new LibraryAuthorizationPolicy()), new CollectionTestItems($items), $repository, $lock, new CollectionNameNormalizer(), new CollectionTestCollectionIds(), new CollectionTestMembershipIds(), new CollectionTestClock(new DateTimeImmutable('2026-09-04 10:00:00.123456+00:00')), $transactions), $repository, $lock, $transactions];
    }
}
