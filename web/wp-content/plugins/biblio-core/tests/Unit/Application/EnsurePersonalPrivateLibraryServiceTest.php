<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\Classification\ClassificationSeedAdoptionAmbiguity;
use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\PersonalLibraryDesignationConflict;
use Biblio\Core\Library\PersonalLibraryRepository;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Library\WritableLibraryMembershipRepository;
use Biblio\Core\Library\WritableLibraryRepository;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class PersonalLibraryInMemoryLibraryRepository implements
    WritableLibraryRepository
{
    private array $libraries = [];

    public function add(Library $library): void
    {
        $this->libraries[$library->id()->value()] = $library;
    }

    public function find(LibraryId $libraryId): ?Library
    {
        return $this->libraries[$libraryId->value()] ?? null;
    }

    public function all(): array
    {
        return array_values($this->libraries);
    }

    public function count(): int
    {
        return count($this->libraries);
    }
}

final class PersonalLibraryInMemoryMembershipRepository implements
    WritableLibraryMembershipRepository
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

    public function count(): int
    {
        return count($this->assignments);
    }

    private function key(LibraryId $libraryId, UserId $userId): string
    {
        return $libraryId->value() . "|" . $userId->value();
    }
}

final class PersonalLibraryInMemoryDesignationRepository implements
    PersonalLibraryRepository
{
    private array $byUser = [];
    private array $byLibrary = [];

    public function findForUser(UserId $userId): ?LibraryId
    {
        return $this->byUser[$userId->value()] ?? null;
    }

    public function designate(UserId $userId, LibraryId $libraryId): void
    {
        if (
            isset($this->byUser[$userId->value()])
            || isset($this->byLibrary[$libraryId->value()])
        ) {
            throw new PersonalLibraryDesignationConflict();
        }

        $this->byUser[$userId->value()] = $libraryId;
        $this->byLibrary[$libraryId->value()] = $userId;
    }

    public function count(): int
    {
        return count($this->byUser);
    }
}

final class PassthroughTransactionManager implements TransactionManager
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}

final class NoopClassificationSeedEvolution implements ClassificationSeedEvolution
{
    public function evolve(LibraryId $libraryId): void
    {
    }

    public function isConverged(LibraryId $libraryId): bool
    {
        return true;
    }

    /** @return list<ClassificationSeedAdoptionAmbiguity> */
    public function ambiguities(LibraryId $libraryId): array
    {
        return [];
    }
}

final class EnsurePersonalPrivateLibraryServiceTest extends TestCase
{
    public function testProvisioningIsIdempotentWithoutUiDependencies(): void
    {
        $repositories = $this->repositories();
        $userId = new UserId("user-x");
        $service = $this->service($userId, ...$repositories);

        $first = $service->ensure();
        $second = $service->ensure();
        $membership = $repositories[1]->findFor($first, $userId);

        self::assertTrue($first->equals($second));
        self::assertSame(1, $repositories[0]->count());
        self::assertSame(1, $repositories[1]->count());
        self::assertSame(1, $repositories[2]->count());
        self::assertSame(
            "Mijn Bibliotheek",
            $repositories[0]->find($first)?->name()->value()
        );
        self::assertNotNull($membership);
        self::assertSame(
            ManagementRole::Owner,
            $membership->membership()->managementRole()
        );
        self::assertSame(
            UseAccess::Direct,
            $membership->membership()->useAccess()
        );
    }

    public function testOtherOwnedLibraryIsNotTreatedAsPersonal(): void
    {
        $repositories = $this->repositories();
        $createLibraryService = $this->createLibraryService(
            $repositories[0],
            $repositories[1]
        );
        $userId = new UserId("user-x");
        $otherLibraryId = new LibraryId("other-library");
        $createLibraryService->create(
            Library::privateLibrary($otherLibraryId),
            $userId
        );
        $service = new EnsurePersonalPrivateLibraryService(
            new ControllableAuthenticatedUser($userId),
            $repositories[2],
            $createLibraryService
        );

        $personalLibraryId = $service->ensure();

        self::assertFalse($personalLibraryId->equals($otherLibraryId));
        self::assertSame(2, $repositories[0]->count());
        self::assertSame(2, $repositories[1]->count());
        self::assertSame(1, $repositories[2]->count());
    }

    private function repositories(): array
    {
        return [
            new PersonalLibraryInMemoryLibraryRepository(),
            new PersonalLibraryInMemoryMembershipRepository(),
            new PersonalLibraryInMemoryDesignationRepository(),
        ];
    }

    private function service(
        UserId $userId,
        PersonalLibraryInMemoryLibraryRepository $libraryRepository,
        PersonalLibraryInMemoryMembershipRepository $membershipRepository,
        PersonalLibraryInMemoryDesignationRepository $designationRepository
    ): EnsurePersonalPrivateLibraryService {
        return new EnsurePersonalPrivateLibraryService(
            new ControllableAuthenticatedUser($userId),
            $designationRepository,
            $this->createLibraryService(
                $libraryRepository,
                $membershipRepository
            )
        );
    }

    private function createLibraryService(
        WritableLibraryRepository $libraryRepository,
        WritableLibraryMembershipRepository $membershipRepository
    ): CreateLibraryService {
        return new CreateLibraryService(
            $libraryRepository,
            $membershipRepository,
            new NoopClassificationSeedEvolution(),
            new PassthroughTransactionManager()
        );
    }
}
