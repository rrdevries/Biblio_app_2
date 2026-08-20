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
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

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
        $serviceX = $this->accessService($userX);
        $serviceY = $this->accessService($userY);

        $allowed = $serviceX->get($libraryA, $itemA->id());

        self::assertNotNull($allowed);
        self::assertTrue($allowed->canUseAsDirectSource());
        self::assertTrue($itemA->id()->equals($allowed->item()->id()));

        self::assertNull($serviceX->get($libraryB, $itemA->id()));
        self::assertNull($serviceX->get($libraryA, $itemB->id()));
        self::assertNull($serviceY->get($libraryA, $itemA->id()));
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

        $ownerAccess = $this->getAccess($libraryId, $owner, $item);
        $memberDirect = $this->getAccess(
            $libraryId,
            new UserId("member-direct"),
            $item
        );
        $memberBorrow = $this->getAccess(
            $libraryId,
            new UserId("member-borrow"),
            $item
        );
        $memberView = $this->getAccess(
            $libraryId,
            new UserId("member-view"),
            $item
        );
        $managerDirect = $this->getAccess(
            $libraryId,
            new UserId("manager-direct"),
            $item
        );
        $managerBorrow = $this->getAccess(
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
            $libraryId,
            new UserId("inactive-direct"),
            $item
        ));
    }

    private function getAccess(
        LibraryId $libraryId,
        UserId $userId,
        Item $item
    ): ?AccessibleLibraryItem {
        return $this->accessService($userId)->get($libraryId, $item->id());
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
            $this->classificationSeedEvolution(),
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

    private function accessService(
        UserId $userId
    ): GetAccessibleLibraryItemService
    {
        return new GetAccessibleLibraryItemService(
            new ControllableAuthenticatedUser($userId),
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
