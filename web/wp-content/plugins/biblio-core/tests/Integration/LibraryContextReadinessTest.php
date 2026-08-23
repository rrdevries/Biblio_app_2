<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActorLibraryContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
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

final class LibraryContextReadinessTest extends PersistenceIntegrationTestCase
{
    public function testActorLibraryListIsNamedDesignatedScopedAndCapabilityAware(): void
    {
        $actor = new UserId("context-actor");
        $foreign = new UserId("context-foreign");
        $alpha = $this->addLibrary("library-alpha", "Alpha Bibliotheek");
        $beta = $this->addLibrary("library-beta", "Beta Bibliotheek");
        $inactive = $this->addLibrary("library-inactive", "Oude Bibliotheek");
        $hidden = $this->addLibrary("library-hidden", "Verborgen Bibliotheek");
        $memberships = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $memberships->add(new LibraryMembershipAssignment(
            $alpha,
            $actor,
            LibraryMembership::owner()
        ));
        $memberships->add(new LibraryMembershipAssignment(
            $beta,
            $actor,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::ViewOnly,
                MembershipStatus::Active,
                AdditionalPermissions::none()
            )
        ));
        $memberships->add(new LibraryMembershipAssignment(
            $inactive,
            $actor,
            new LibraryMembership(
                ManagementRole::Member,
                UseAccess::Direct,
                MembershipStatus::Inactive,
                AdditionalPermissions::none()
            )
        ));
        $memberships->add(new LibraryMembershipAssignment(
            $hidden,
            $foreign,
            LibraryMembership::owner()
        ));
        (new WpdbPersonalLibraryRepository(
            $this->database,
            $this->tableNames
        ))->designate($actor, $alpha);
        $service = $this->service($actor);

        $views = $service->myLibraries();

        self::assertCount(2, $views);
        self::assertSame(
            ["library-alpha", "library-beta"],
            array_map(
                static fn ($view): string => $view->libraryId()->value(),
                $views
            )
        );
        self::assertSame("Alpha Bibliotheek", $views[0]->name()->value());
        self::assertTrue($views[0]->isDesignatedPersonal());
        self::assertTrue($views[0]->capabilities()->canAddCatalogItem());
        self::assertTrue($views[0]->capabilities()->canUseItemDirectly());
        self::assertFalse($views[1]->isDesignatedPersonal());
        self::assertFalse($views[1]->capabilities()->canAddCatalogItem());
        self::assertFalse($views[1]->capabilities()->canUseItemDirectly());
        self::assertSame(
            "Beta Bibliotheek",
            $service->get($beta)->name()->value()
        );

        foreach ([$inactive, $hidden, new LibraryId("library-missing")] as $id) {
            try {
                $service->get($id);
                self::fail("Foreign or inactive Library context was exposed.");
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    private function addLibrary(string $id, string $name): LibraryId
    {
        $libraryId = new LibraryId($id);
        (new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        ))->add(Library::privateLibrary($libraryId, new LibraryName($name)));

        return $libraryId;
    }

    private function service(UserId $actor): LibraryContextQueryService
    {
        return new LibraryContextQueryService(
            new ControllableAuthenticatedUser($actor),
            new WpdbActorLibraryContextRepository(
                $this->database,
                $this->tableNames
            ),
            new LibraryAuthorizationPolicy()
        );
    }
}
