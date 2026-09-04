<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class ItemAccessInMemoryItemRepository implements ItemRepository
{
    private array $items = [];

    public function add(Item $item): void
    {
        $this->items[$item->id()->value()] = $item;
    }

    public function findInLibrary(
        ItemId $itemId,
        LibraryId $libraryId
    ): ?Item {
        $item = $this->items[$itemId->value()] ?? null;

        if ($item === null || !$libraryId->equals($item->libraryId())) {
            return null;
        }

        return $item;
    }

    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) { $result[$itemId->value()] = $this->findInLibrary($itemId, $libraryId); }
        return $result;
    }
}

final class ItemAccessInMemoryMembershipRepository implements
    LibraryMembershipRepository
{
    private array $assignments = [];

    public function add(LibraryMembershipAssignment $assignment): void
    {
        $this->assignments[$this->key(
            $assignment->libraryId(),
            $assignment->userId()
        )] = $assignment;
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        return $this->assignments[$this->key($libraryId, $userId)] ?? null;
    }

    private function key(LibraryId $libraryId, UserId $userId): string
    {
        return $libraryId->value() . "|" . $userId->value();
    }
}

final class GetAccessibleLibraryItemServiceTest extends TestCase
{
    public function testVisibleItemAndDirectUseRemainSeparate(): void
    {
        [$service, $actor, $items, $memberships] = $this->fixture();
        $libraryId = new LibraryId("library-a");
        $item = Item::active(
            new ItemId("item-a"),
            $libraryId,
            new EditionId("edition-e")
        );
        $items->add($item);
        $borrowUser = new UserId("borrow-user");
        $directUser = new UserId("direct-user");
        $memberships->add($this->membership(
            $libraryId,
            $borrowUser,
            ManagementRole::Manager,
            UseAccess::Borrow
        ));
        $memberships->add($this->membership(
            $libraryId,
            $directUser,
            ManagementRole::Member,
            UseAccess::Direct
        ));

        $actor->authenticateAs($borrowUser);
        $borrowAccess = $service->get($libraryId, $item->id());
        $actor->authenticateAs($directUser);
        $directAccess = $service->get($libraryId, $item->id());

        self::assertNotNull($borrowAccess);
        self::assertFalse($borrowAccess->canUseAsDirectSource());
        self::assertNotNull($directAccess);
        self::assertTrue($directAccess->canUseAsDirectSource());
    }

    public function testItemCannotCrossLibraryContext(): void
    {
        [$service, $actor, $items, $memberships] = $this->fixture();
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $user = new UserId("user-x");
        $item = Item::active(
            new ItemId("item-a"),
            $libraryA,
            new EditionId("edition-e")
        );
        $items->add($item);
        $memberships->add($this->membership(
            $libraryB,
            $user,
            ManagementRole::Member,
            UseAccess::Direct
        ));

        $actor->authenticateAs($user);

        self::assertNull($service->get($libraryB, $item->id()));
    }

    public function testArchivedItemIsNotAnAvailableCurrentSource(): void
    {
        [$service, $actor, $items, $memberships] = $this->fixture();
        $libraryId = new LibraryId("library-a");
        $user = new UserId("direct-user");
        $item = Item::active(
            new ItemId("item-a"),
            $libraryId,
            new EditionId("edition-e")
        )->archive();
        $items->add($item);
        $memberships->add($this->membership(
            $libraryId,
            $user,
            ManagementRole::Member,
            UseAccess::Direct
        ));
        $actor->authenticateAs($user);

        self::assertNull($service->get($libraryId, $item->id()));
    }

    public function testActorCannotUseAnotherUsersMembership(): void
    {
        [$service, $actor, $items, $memberships] = $this->fixture();
        $libraryId = new LibraryId("library-a");
        $contextUser = new UserId("context-user");
        $item = Item::active(
            new ItemId("item-a"),
            $libraryId,
            new EditionId("edition-e")
        );
        $items->add($item);
        $memberships->add($this->membership(
            $libraryId,
            $contextUser,
            ManagementRole::Member,
            UseAccess::Direct
        ));

        $actor->authenticateAs(new UserId("authenticated-user"));

        self::assertNull($service->get($libraryId, $item->id()));
    }

    public function testForeignItemReturnedByRepositoryIsRejected(): void
    {
        $contextLibrary = new LibraryId("library-a");
        $foreignItem = Item::active(
            new ItemId("item-b"),
            new LibraryId("library-b"),
            new EditionId("edition-e")
        );
        $user = new UserId("user-x");
        $memberships = new ItemAccessInMemoryMembershipRepository();
        $memberships->add($this->membership(
            $contextLibrary,
            $user,
            ManagementRole::Member,
            UseAccess::Direct
        ));
        $unscopedRepository = new class($foreignItem) implements ItemRepository {
            public function __construct(private Item $item)
            {
            }

            public function add(Item $item): void
            {
            }

            public function findInLibrary(
                ItemId $itemId,
                LibraryId $libraryId
            ): ?Item {
                return $this->item;
            }

            public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
            {
                return array_fill_keys(array_map(static fn (ItemId $id): string => $id->value(), $itemIds), $this->item);
            }
        };
        $service = new GetAccessibleLibraryItemService(
            new ControllableAuthenticatedUser($user),
            $unscopedRepository,
            new LibraryAccessService(
                $memberships,
                new LibraryAuthorizationPolicy()
            )
        );

        self::assertNull($service->get($contextLibrary, $foreignItem->id()));
    }

    private function fixture(): array
    {
        $items = new ItemAccessInMemoryItemRepository();
        $memberships = new ItemAccessInMemoryMembershipRepository();
        $actor = new ControllableAuthenticatedUser();

        return [
            new GetAccessibleLibraryItemService(
                $actor,
                $items,
                new LibraryAccessService(
                    $memberships,
                    new LibraryAuthorizationPolicy()
                )
            ),
            $actor,
            $items,
            $memberships,
        ];
    }

    private function membership(
        LibraryId $libraryId,
        UserId $userId,
        ManagementRole $role,
        UseAccess $useAccess
    ): LibraryMembershipAssignment {
        return new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                $role,
                $useAccess,
                MembershipStatus::Active
            )
        );
    }
}
