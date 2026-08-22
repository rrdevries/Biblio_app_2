<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Classification\ClassificationTermActivity;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Catalog\Classification\LibrarySubjectRepository;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
use Biblio\Core\Catalog\Classification\WritableLibraryGenreRepository;
use Biblio\Core\Catalog\LibraryWorkRepresentationRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\LibraryMutationLock;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class SingleClassificationMembershipRepository implements
    LibraryMembershipRepository
{
    public function __construct(
        private readonly LibraryMembershipAssignment $assignment
    ) {
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        return $this->assignment->libraryId()->equals($libraryId)
            && $this->assignment->userId()->equals($userId)
                ? $this->assignment
                : null;
    }
}

final class ClassificationManagementAuthorizationTest extends TestCase
{
    public function testContextAuthorizationPrecedesRepresentationLookupAndTransaction(): void
    {
        [$actor, $access, $libraryId] = $this->itemOnlyManager();
        $represented = $this->createMock(
            LibraryWorkRepresentationRepository::class
        );
        $represented->expects(self::never())->method("findRepresentedWork");
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::never())->method("run");
        $contexts = $this->createStub(
            WritableLibraryCatalogContextRepository::class
        );
        $libraryLock = $this->createStub(LibraryMutationLock::class);
        $service = new CreateLibraryCatalogContextService(
            $actor,
            $access,
            $represented,
            new LibraryCatalogContextInitializer(
                $contexts,
                new LibraryCatalogSelectionResolver(
                    $this->createStub(LibraryBookTypeRepository::class),
                    $this->createStub(LibraryGenreRepository::class),
                    $this->createStub(LibrarySubjectRepository::class)
                ),
                $libraryLock
            ),
            $libraryLock,
            new LibraryCatalogContextActivity(
                $this->createStub(ActivityEventFactory::class)
            ),
            $this->createStub(ActivityEventAppender::class),
            $transactions
        );

        $this->expectException(AuthorizationException::class);
        $service->createForRepresentedWork(
            $libraryId,
            new WorkId("sensitive-work"),
            new LibraryCatalogSelection(new LibraryBookTypeId("book-a"))
        );
    }

    public function testItemAddPermissionCannotReachTermRepositoryOrTransaction(): void
    {
        [$actor, $access, $libraryId] = $this->itemOnlyManager();
        $terms = $this->createMock(WritableLibraryGenreRepository::class);
        $terms->expects(self::never())->method("add");
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::never())->method("run");
        $service = new ManageLibraryGenresService(
            $actor,
            $access,
            $terms,
            ClassificationNameNormalizer::create(),
            new ClassificationTermActivity(
                $this->createStub(ActivityEventFactory::class)
            ),
            $this->createStub(ActivityEventAppender::class),
            $transactions
        );

        $this->expectException(AuthorizationException::class);
        $service->create(
            $libraryId,
            new LibraryGenreId("genre-secret"),
            new ClassificationTermName("Secret")
        );
    }

    /** @return array{ControllableAuthenticatedUser, LibraryAccessService, LibraryId} */
    private function itemOnlyManager(): array
    {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("manager");
        $assignment = new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Direct,
                MembershipStatus::Active,
                AdditionalPermissions::fromValues(
                    AdditionalPermissions::CATALOG_ITEM_ADD
                )
            )
        );

        return [
            new ControllableAuthenticatedUser($userId),
            new LibraryAccessService(
                new SingleClassificationMembershipRepository($assignment),
                new LibraryAuthorizationPolicy()
            ),
            $libraryId,
        ];
    }
}
