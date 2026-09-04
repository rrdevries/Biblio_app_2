<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Library\ActorLibraryContext;
use Biblio\Core\Application\Library\ActorLibraryContextRepository;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class ContextQueryInMemoryRepository implements ActorLibraryContextRepository
{
    /** @param list<ActorLibraryContext> $records */
    public function __construct(private array $records)
    {
    }

    public function findForActor(
        LibraryId $libraryId,
        UserId $actorId
    ): ?ActorLibraryContext {
        foreach ($this->records as $record) {
            if (
                $record->library()->id()->equals($libraryId)
                && $record->membership()->userId()->equals($actorId)
            ) {
                return $record;
            }
        }

        return null;
    }

    public function listForActor(UserId $actorId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (ActorLibraryContext $record): bool =>
                $record->membership()->userId()->equals($actorId)
        ));
    }
}

final class LibraryContextQueryServiceTest extends TestCase
{
    public function testActorListContainsIdentityDesignationAndCapabilities(): void
    {
        $actor = new UserId("actor");
        $owner = $this->record(
            "library-owner",
            "Mijn Bibliotheek",
            $actor,
            LibraryMembership::owner(),
            true
        );
        $member = $this->record(
            "library-member",
            "Samen lezen",
            $actor,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::ViewOnly,
                MembershipStatus::Active,
                AdditionalPermissions::none()
            ),
            false
        );
        $inactive = $this->record(
            "library-inactive",
            "Oud",
            $actor,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::Direct,
                MembershipStatus::Inactive,
                AdditionalPermissions::none()
            ),
            false
        );
        $foreign = $this->record(
            "library-foreign",
            "Verborgen",
            new UserId("other"),
            LibraryMembership::owner(),
            false
        );
        $service = $this->service($actor, [$owner, $member, $inactive, $foreign]);

        $views = $service->myLibraries();

        self::assertCount(2, $views);
        self::assertSame("library-owner", $views[0]->libraryId()->value());
        self::assertSame("Mijn Bibliotheek", $views[0]->name()->value());
        self::assertTrue($views[0]->isDesignatedPersonal());
        self::assertTrue($views[0]->capabilities()->canViewCollection());
        self::assertTrue($views[0]->capabilities()->canAddCatalogItem());
        self::assertTrue($views[0]->capabilities()->canManageCollections());
        self::assertTrue($views[0]->capabilities()->canUseItemDirectly());
        self::assertSame("library-member", $views[1]->libraryId()->value());
        self::assertFalse($views[1]->isDesignatedPersonal());
        self::assertTrue($views[1]->capabilities()->canViewCollection());
        self::assertFalse($views[1]->capabilities()->canAddCatalogItem());
        self::assertFalse($views[1]->capabilities()->canManageCollections());
        self::assertFalse($views[1]->capabilities()->canUseItemDirectly());
    }

    public function testExplicitContextRejectsMissingForeignAndInactiveMembership(): void
    {
        $actor = new UserId("actor");
        $inactive = $this->record(
            "library-inactive",
            "Oud",
            $actor,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::Direct,
                MembershipStatus::Inactive,
                AdditionalPermissions::none()
            ),
            false
        );
        $service = $this->service($actor, [$inactive]);

        foreach (["library-inactive", "library-foreign", "library-missing"] as $id) {
            try {
                $service->get(new LibraryId($id));
                self::fail("Unavailable Library context was accepted: {$id}");
            } catch (AuthorizationException $exception) {
                self::assertSame(
                    "Library context is not available to the authenticated user.",
                    $exception->getMessage()
                );
            }
        }
    }

    /** @param list<ActorLibraryContext> $records */
    private function service(UserId $actor, array $records): LibraryContextQueryService
    {
        return new LibraryContextQueryService(
            new ControllableAuthenticatedUser($actor),
            new ContextQueryInMemoryRepository($records),
            new LibraryAuthorizationPolicy()
        );
    }

    private function record(
        string $libraryId,
        string $name,
        UserId $userId,
        LibraryMembership $membership,
        bool $designated
    ): ActorLibraryContext {
        $id = new LibraryId($libraryId);

        return new ActorLibraryContext(
            Library::privateLibrary($id, new LibraryName($name)),
            new LibraryMembershipAssignment($id, $userId, $membership),
            $designated
        );
    }
}
