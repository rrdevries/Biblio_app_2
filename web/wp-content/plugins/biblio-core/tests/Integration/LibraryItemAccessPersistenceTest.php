<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\AccessibleLibraryItem;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;

final class LibraryItemAccessPersistenceTest extends
    PersistenceIntegrationTestCase
{
    public function testItemAccessIsStrictlyScopedByLibraryAndUser(): void
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $this->createLibrary($libraryA, $userX);
        $this->createLibrary($libraryB, new UserId("owner-b"));
        $this->addMembership(
            $libraryB,
            $userX,
            ManagementRole::Member,
            UseAccess::Direct
        );
        [$itemA, $itemB] = $this->createSharedCatalogItems(
            $libraryA,
            $libraryB
        );
        $service = $this->accessService();

        $allowed = $service->get(
            $userX,
            new LibraryContext($libraryA, $userX),
            $itemA->id()
        );

        self::assertNotNull($allowed);
        self::assertTrue($allowed->canUseAsDirectSource());
        self::assertTrue($itemA->id()->equals($allowed->item()->id()));

        self::assertNull($service->get(
            $userX,
            new LibraryContext($libraryB, $userX),
            $itemA->id()
        ));
        self::assertNull($service->get(
            $userX,
            new LibraryContext($libraryA, $userX),
            $itemB->id()
        ));
        self::assertNull($service->get(
            $userY,
            new LibraryContext($libraryA, $userY),
            $itemA->id()
        ));
        self::assertNull($service->get(
            $userY,
            new LibraryContext($libraryA, $userX),
            $itemA->id()
        ));
    }

    public function testUseAccessNotManagementRoleControlsDirectUse(): void
    {
        $libraryId = new LibraryId("library-a");
        $owner = new UserId("owner");
        $this->createLibrary($libraryId, $owner);
        [$item] = $this->createSharedCatalogItems($libraryId);

        $memberships = [
            ["member-direct", ManagementRole::Member, UseAccess::Direct, MembershipStatus::Active],
            ["member-borrow", ManagementRole::Member, UseAccess::Borrow, MembershipStatus::Active],
            ["member-view", ManagementRole::Member, UseAccess::ViewOnly, MembershipStatus::Active],
            ["manager-direct", ManagementRole::Manager, UseAccess::Direct, MembershipStatus::Active],
            ["manager-borrow", ManagementRole::Manager, UseAccess::Borrow, MembershipStatus::Active],
            ["inactive-direct", ManagementRole::Member, UseAccess::Direct, MembershipStatus::Inactive],
        ];

        foreach ($memberships as [$user, $role, $useAccess, $status]) {
            $this->addMembership(
                $libraryId,
                new UserId($user),
                $role,
                $useAccess,
                $status
            );
        }

        $service = $this->accessService();
        $ownerAccess = $this->getAccess($service, $libraryId, $owner, $item);
        $memberDirect = $this->getAccess(
            $service,
            $libraryId,
            new UserId("member-direct"),
            $item
        );
        $memberBorrow = $this->getAccess(
            $service,
            $libraryId,
            new UserId("member-borrow"),
            $item
        );
        $memberView = $this->getAccess(
            $service,
            $libraryId,
            new UserId("member-view"),
            $item
        );
        $managerDirect = $this->getAccess(
            $service,
            $libraryId,
            new UserId("manager-direct"),
            $item
        );
        $managerBorrow = $this->getAccess(
            $service,
            $libraryId,
            new UserId("manager-borrow"),
            $item
        );

        self::assertNotNull($ownerAccess);
        self::assertTrue($ownerAccess->canUseAsDirectSource());
        self::assertNotNull($memberDirect);
        self::assertTrue($memberDirect->canUseAsDirectSource());
        self::assertNotNull($memberBorrow);
        self::assertFalse($memberBorrow->canUseAsDirectSource());
        self::assertNotNull($memberView);
        self::assertFalse($memberView->canUseAsDirectSource());
        self::assertNotNull($managerDirect);
        self::assertTrue($managerDirect->canUseAsDirectSource());
        self::assertNotNull($managerBorrow);
        self::assertFalse($managerBorrow->canUseAsDirectSource());
        self::assertNull($this->getAccess(
            $service,
            $libraryId,
            new UserId("inactive-direct"),
            $item
        ));
    }

    private function getAccess(
        GetAccessibleLibraryItemService $service,
        LibraryId $libraryId,
        UserId $userId,
        Item $item
    ): ?AccessibleLibraryItem {
        return $service->get(
            $userId,
            new LibraryContext($libraryId, $userId),
            $item->id()
        );
    }

    private function createSharedCatalogItems(
        LibraryId $libraryA,
        ?LibraryId $libraryB = null
    ): array {
        $work = new Work(new WorkId("work-w"), "Shared Work");
        $edition = new Edition(new EditionId("edition-e"), $work->id());
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add($work);
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add($edition);
        $itemRepository = $this->itemRepository();
        $items = [Item::active(
            new ItemId("item-a"),
            $libraryA,
            $edition->id()
        )];
        $itemRepository->add($items[0]);

        if ($libraryB !== null) {
            $items[] = Item::active(
                new ItemId("item-b"),
                $libraryB,
                $edition->id()
            );
            $itemRepository->add($items[1]);
        }

        return $items;
    }

    private function createLibrary(LibraryId $libraryId, UserId $owner): void
    {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            $this->membershipRepository(),
            new WpdbTransactionManager($this->database)
        ))->create(Library::privateLibrary($libraryId), $owner);
    }

    private function addMembership(
        LibraryId $libraryId,
        UserId $userId,
        ManagementRole $role,
        UseAccess $useAccess,
        MembershipStatus $status = MembershipStatus::Active
    ): void {
        $this->membershipRepository()->add(
            new LibraryMembershipAssignment(
                $libraryId,
                $userId,
                new LibraryMembership($role, $useAccess, $status)
            )
        );
    }

    private function accessService(): GetAccessibleLibraryItemService
    {
        return new GetAccessibleLibraryItemService(
            $this->itemRepository(),
            new LibraryAccessService(
                $this->membershipRepository(),
                new LibraryAuthorizationPolicy()
            )
        );
    }

    private function itemRepository(): WpdbItemRepository
    {
        return new WpdbItemRepository($this->database, $this->tableNames);
    }

    private function membershipRepository(): WpdbLibraryMembershipRepository
    {
        return new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
    }
}
