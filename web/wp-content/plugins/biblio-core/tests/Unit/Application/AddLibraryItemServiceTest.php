<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WritableEditionRepository;
use Biblio\Core\Catalog\WritableItemRepository;
use Biblio\Core\Catalog\WritableWorkRepository;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CatalogApplicationStore
{
    public array $works = [];
    public array $editions = [];
    public array $items = [];
    public array $operations = [];
    public ?string $failOn = null;
    public int $workFindCount = 0;
    public int $editionFindCount = 0;
}

final readonly class CatalogApplicationWorkRepository implements
    WritableWorkRepository
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function add(Work $work): void
    {
        $this->store->operations[] = "work";

        if ($this->store->failOn === "work") {
            throw new RuntimeException("Work failure.");
        }

        $this->store->works[$work->id()->value()] = $work;
    }

    public function find(WorkId $workId): ?Work
    {
        $this->store->workFindCount++;

        return $this->store->works[$workId->value()] ?? null;
    }
}

final readonly class CatalogApplicationEditionRepository implements
    WritableEditionRepository
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function add(Edition $edition): void
    {
        $this->store->operations[] = "edition";

        if ($this->store->failOn === "edition") {
            throw new RuntimeException("Edition failure.");
        }

        $this->store->editions[$edition->id()->value()] = $edition;
    }

    public function find(EditionId $editionId): ?Edition
    {
        $this->store->editionFindCount++;

        return $this->store->editions[$editionId->value()] ?? null;
    }
}

final readonly class CatalogApplicationItemRepository implements
    WritableItemRepository
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function add(Item $item): void
    {
        $this->store->operations[] = "item";

        if ($this->store->failOn === "item") {
            throw new RuntimeException("Item failure.");
        }

        $this->store->items[$item->id()->value()] = $item;
    }

    public function findInLibrary(
        ItemId $itemId,
        LibraryId $libraryId
    ): ?Item {
        $item = $this->store->items[$itemId->value()] ?? null;

        return $item !== null && $libraryId->equals($item->libraryId())
            ? $item
            : null;
    }
}

final class CatalogApplicationTransactionManager implements TransactionManager
{
    public int $runCount = 0;

    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->runCount++;
        $works = $this->store->works;
        $editions = $this->store->editions;
        $items = $this->store->items;

        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->store->works = $works;
            $this->store->editions = $editions;
            $this->store->items = $items;

            throw $failure;
        }
    }
}

final class CatalogApplicationMembershipRepository implements
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

final class AddLibraryItemServiceTest extends TestCase
{
    public function testExistingEditionPathWritesOnlyLibraryScopedItem(): void
    {
        [$service, , $store, , $transaction] = $this->fixture();
        $edition = new Edition(
            new EditionId("edition-existing"),
            new WorkId("work-existing")
        );
        $store->editions[$edition->id()->value()] = $edition;

        $item = $service->addForExistingEdition(
            new LibraryId("library-a"),
            new ItemId("item-new"),
            $edition->id()
        );

        self::assertSame(["item"], $store->operations);
        self::assertSame(1, $transaction->runCount);
        self::assertSame(0, count($store->works));
        self::assertSame(1, count($store->editions));
        self::assertSame(1, count($store->items));
        self::assertSame("library-a", $item->libraryId()->value());
        self::assertTrue($edition->id()->equals($item->editionId()));
    }

    public function testNewEditionPathRequiresAndReusesExistingWork(): void
    {
        [$service, , $store, , $transaction] = $this->fixture(
            ManagementRole::Manager,
            UseAccess::ViewOnly
        );
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $store->works[$work->id()->value()] = $work;

        $item = $service->addWithNewEditionForExistingWork(
            new LibraryId("library-a"),
            new ItemId("item-new"),
            new EditionId("edition-new"),
            $work->id()
        );

        self::assertSame(["edition", "item"], $store->operations);
        self::assertSame(1, $transaction->runCount);
        self::assertSame(1, count($store->works));
        self::assertSame("edition-new", $item->editionId()->value());
        self::assertSame(
            "work-existing",
            $store->editions["edition-new"]->workId()->value()
        );
    }

    public function testNewWorkPathBuildsOneConsistentCatalogChain(): void
    {
        [$service, , $store] = $this->fixture();

        $item = $service->addWithNewWorkAndEdition(
            new LibraryId("library-a"),
            new ItemId("item-new"),
            new WorkId("work-new"),
            "New Work",
            new EditionId("edition-new")
        );

        self::assertSame(["work", "edition", "item"], $store->operations);
        self::assertSame("work-new", $store->works["work-new"]->id()->value());
        self::assertSame(
            "work-new",
            $store->editions["edition-new"]->workId()->value()
        );
        self::assertSame("library-a", $item->libraryId()->value());
        self::assertSame("edition-new", $item->editionId()->value());
    }

    public function testAuthorizationPrecedesCentralCatalogLookups(): void
    {
        [$service, , $store] = $this->fixture(ManagementRole::Member);

        try {
            $service->addWithNewEditionForExistingWork(
                new LibraryId("library-a"),
                new ItemId("item-new"),
                new EditionId("edition-new"),
                new WorkId("work-secret")
            );
            self::fail("Member was allowed to manage the catalog.");
        } catch (AuthorizationException $exception) {
            self::assertSame(FailureReason::AuthorizationDenied, $exception->reason());
            self::assertSame(0, $store->workFindCount);
            self::assertSame(0, $store->editionFindCount);
            self::assertSame([], $store->operations);
        }
    }

    public function testUnauthenticatedActorFailsWithStableReason(): void
    {
        [$service, $actor, $store] = $this->fixture();
        $actor->logOut();

        try {
            $service->addForExistingEdition(
                new LibraryId("library-a"),
                new ItemId("item-new"),
                new EditionId("edition-secret")
            );
            self::fail("Unauthenticated catalog mutation was accepted.");
        } catch (AuthenticationException $exception) {
            self::assertSame(
                FailureReason::AuthenticationRequired,
                $exception->reason()
            );
            self::assertSame(0, $store->editionFindCount);
            self::assertSame([], $store->operations);
        }
    }

    public function testExistingCentralRecordsAreRequiredByExplicitPaths(): void
    {
        [$service, , $store, , $transaction] = $this->fixture();

        try {
            $service->addForExistingEdition(
                new LibraryId("library-a"),
                new ItemId("item-a"),
                new EditionId("missing-edition")
            );
            self::fail("Missing Edition was accepted.");
        } catch (ValidationException $exception) {
            self::assertSame(FailureReason::ValidationFailed, $exception->reason());
        }

        try {
            $service->addWithNewEditionForExistingWork(
                new LibraryId("library-a"),
                new ItemId("item-b"),
                new EditionId("edition-b"),
                new WorkId("missing-work")
            );
            self::fail("Missing Work was accepted.");
        } catch (ValidationException $exception) {
            self::assertSame(FailureReason::ValidationFailed, $exception->reason());
        }

        self::assertSame(0, $transaction->runCount);
        self::assertSame([], $store->operations);
    }

    #[DataProvider("compoundFailureCases")]
    public function testCompoundCreationRollsBackEveryFailedStep(
        string $path,
        string $failedStep
    ): void {
        [$service, , $store] = $this->fixture();
        $store->failOn = $failedStep;

        if ($path === "new-edition") {
            $work = new Work(new WorkId("work-existing"), "Existing Work");
            $store->works[$work->id()->value()] = $work;
        }

        try {
            if ($path === "new-edition") {
                $service->addWithNewEditionForExistingWork(
                    new LibraryId("library-a"),
                    new ItemId("item-new"),
                    new EditionId("edition-new"),
                    new WorkId("work-existing")
                );
            } else {
                $service->addWithNewWorkAndEdition(
                    new LibraryId("library-a"),
                    new ItemId("item-new"),
                    new WorkId("work-new"),
                    "New Work",
                    new EditionId("edition-new")
                );
            }
            self::fail("Configured compound failure did not occur.");
        } catch (RuntimeException) {
            self::assertArrayNotHasKey("work-new", $store->works);
            self::assertArrayNotHasKey("edition-new", $store->editions);
            self::assertArrayNotHasKey("item-new", $store->items);
        }
    }

    public static function compoundFailureCases(): iterable
    {
        yield "new Edition fails at Edition" => ["new-edition", "edition"];
        yield "new Edition fails at Item" => ["new-edition", "item"];
        yield "new Work fails at Work" => ["new-work", "work"];
        yield "new Work fails at Edition" => ["new-work", "edition"];
        yield "new Work fails at Item" => ["new-work", "item"];
    }

    private function fixture(
        ManagementRole $role = ManagementRole::Owner,
        UseAccess $useAccess = UseAccess::Direct,
        MembershipStatus $status = MembershipStatus::Active
    ): array {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("user-1");
        $actor = new ControllableAuthenticatedUser($userId);
        $store = new CatalogApplicationStore();
        $memberships = new CatalogApplicationMembershipRepository();
        $memberships->add(new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership($role, $useAccess, $status)
        ));
        $transaction = new CatalogApplicationTransactionManager($store);

        return [
            new AddLibraryItemService(
                $actor,
                new LibraryAccessService(
                    $memberships,
                    new LibraryAuthorizationPolicy()
                ),
                new CatalogApplicationWorkRepository($store),
                new CatalogApplicationEditionRepository($store),
                new CatalogApplicationItemRepository($store),
                $transaction
            ),
            $actor,
            $store,
            $memberships,
            $transaction,
        ];
    }
}
