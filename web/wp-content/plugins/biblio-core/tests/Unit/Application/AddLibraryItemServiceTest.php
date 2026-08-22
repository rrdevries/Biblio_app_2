<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextActivity;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityActorSnapshot;
use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntityIdentity;
use Biblio\Core\Audit\ActivityEntitySnapshot;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Audit\ActivityEventId;
use Biblio\Core\Audit\ActivityEventKey;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Audit\ActivityLabel;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\ClassificationNormalizedName;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Catalog\Classification\LibrarySubjectRepository;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CatalogApplicationStore
{
    public array $works = [];
    public array $editions = [];
    public array $items = [];
    public array $contexts = [];
    public array $events = [];
    public array $operations = [];
    public ?string $failOn = null;
    public int $workFindCount = 0;
    public int $editionFindCount = 0;
    public int $contextFindCount = 0;
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

final readonly class CatalogApplicationContextRepository implements
    WritableLibraryCatalogContextRepository
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function add(LibraryCatalogContext $context): void
    {
        $this->store->operations[] = "context";

        if ($this->store->failOn === "context") {
            throw new RuntimeException("Context failure.");
        }

        $this->store->contexts[$this->key(
            $context->libraryId(),
            $context->workId()
        )] = $context;
    }

    public function replaceIfVersionMatches(
        LibraryCatalogContext $replacement,
        LibraryCatalogContextVersion $expectedVersion
    ): bool {
        throw new RuntimeException("Context replacement is not used here.");
    }

    public function find(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext {
        $this->store->contextFindCount++;

        return $this->stored($libraryId, $workId);
    }

    public function findForUpdate(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext {
        return $this->stored($libraryId, $workId);
    }

    private function stored(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext {
        return $this->store->contexts[$this->key($libraryId, $workId)]
            ?? null;
    }

    private function key(LibraryId $libraryId, WorkId $workId): string
    {
        return $libraryId->value() . "|" . $workId->value();
    }
}

final readonly class CatalogApplicationActivityFactory implements
    ActivityEventFactory
{
    public function create(
        UserId $actorId,
        LibraryId $libraryId,
        ActivityEventKey $eventKey,
        ActivityEntityIdentity $primaryEntity,
        array $relatedEntities,
        array $changes
    ): ActivityEvent {
        return new ActivityEvent(
            new ActivityEventId("event-1"),
            $libraryId,
            new DateTimeImmutable("2026-08-22T12:00:00.000000+00:00"),
            new ActivityActorSnapshot(
                $actorId,
                new ActivityLabel("Catalog Actor")
            ),
            new ActivityEventSource("test.catalog"),
            $eventKey,
            $primaryEntity,
            $relatedEntities,
            $changes
        );
    }
}

final readonly class CatalogApplicationActivityAppender implements
    ActivityEventAppender
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function append(ActivityEvent $event): void
    {
        $this->store->operations[] = "event";

        if ($this->store->failOn === "event") {
            throw new RuntimeException("Activity Event failure.");
        }

        $this->store->events[] = $event;
    }
}

final readonly class CatalogApplicationLibraryLock implements
    LibraryMutationLock
{
    public function __construct(private CatalogApplicationStore $store)
    {
    }

    public function acquire(LibraryId $libraryId): void
    {
        $this->store->operations[] = "lock";
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
        $contexts = $this->store->contexts;
        $events = $this->store->events;

        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->store->works = $works;
            $this->store->editions = $editions;
            $this->store->items = $items;
            $this->store->contexts = $contexts;
            $this->store->events = $events;

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
        $store->works["work-existing"] = new Work(
            new WorkId("work-existing"),
            "Existing Work"
        );
        $store->contexts["library-a|work-existing"] =
            LibraryCatalogContext::create(
                new LibraryId("library-a"),
                new WorkId("work-existing"),
                $this->selection()
            );

        $item = $service->addForExistingEdition(
            new LibraryId("library-a"),
            new ItemId("item-new"),
            $edition->id()
        );

        self::assertSame(["item"], $store->operations);
        self::assertSame(1, $transaction->runCount);
        self::assertSame(1, count($store->works));
        self::assertSame(1, count($store->editions));
        self::assertSame(1, count($store->items));
        self::assertSame("library-a", $item->libraryId()->value());
        self::assertTrue($edition->id()->equals($item->editionId()));
    }

    public function testNewEditionPathRequiresAndReusesExistingWork(): void
    {
        [$service, , $store, , $transaction] = $this->fixture(
            ManagementRole::Manager,
            UseAccess::ViewOnly,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_ITEM_ADD
            )
        );
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $store->works[$work->id()->value()] = $work;

        $item = $service->addWithNewEditionForExistingWork(
            new LibraryId("library-a"),
            new ItemId("item-new"),
            new EditionId("edition-new"),
            $work->id(),
            $this->initialization()
        );

        self::assertSame(
            ["edition", "lock", "context", "item", "event"],
            $store->operations
        );
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
            new EditionId("edition-new"),
            $this->initialization()
        );

        self::assertSame(
            ["work", "edition", "lock", "context", "item", "event"],
            $store->operations
        );
        self::assertSame("work-new", $store->works["work-new"]->id()->value());
        self::assertSame(
            "work-new",
            $store->editions["edition-new"]->workId()->value()
        );
        self::assertSame("library-a", $item->libraryId()->value());
        self::assertSame("edition-new", $item->editionId()->value());
        self::assertCount(1, $store->events);
        self::assertSame(
            "library_catalog_context.created",
            $store->events[0]->eventKey()->value()
        );
        self::assertSame(
            "LibraryCatalogContext",
            $store->events[0]->primaryEntity()->entityType()
        );
        self::assertSame(
            "work-new",
            $store->events[0]->relatedEntities()[0]
                ->identity()->entityId()
        );
        self::assertCount(3, $store->events[0]->changes());
    }

    public function testMissingContextRequiresTypedInitialization(): void
    {
        [$service, , $store] = $this->fixture();
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $edition = new Edition(new EditionId("edition-existing"), $work->id());
        $store->works[$work->id()->value()] = $work;
        $store->editions[$edition->id()->value()] = $edition;

        try {
            $service->addForExistingEdition(
                new LibraryId("library-a"),
                new ItemId("item-new"),
                $edition->id()
            );
            self::fail("Contextless Item-add was accepted.");
        } catch (ValidationException $exception) {
            self::assertSame(
                FailureReason::ValidationFailed,
                $exception->reason()
            );
        }

        self::assertSame(["lock"], $store->operations);
        self::assertSame([], $store->items);
        self::assertSame([], $store->contexts);
        self::assertSame([], $store->events);
    }

    public function testExistingContextIgnoresInitializationWithoutMutation(): void
    {
        [$service, , $store] = $this->fixture();
        $libraryId = new LibraryId("library-a");
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $edition = new Edition(new EditionId("edition-existing"), $work->id());
        $existing = LibraryCatalogContext::create(
            $libraryId,
            $work->id(),
            new LibraryCatalogSelection(
                new LibraryBookTypeId("book-inactive")
            )
        );
        $store->works[$work->id()->value()] = $work;
        $store->editions[$edition->id()->value()] = $edition;
        $store->contexts["library-a|work-existing"] = $existing;

        $service->addForExistingEdition(
            $libraryId,
            new ItemId("item-new"),
            $edition->id(),
            $this->initialization()
        );

        self::assertSame(["item"], $store->operations);
        self::assertSame(
            $existing,
            $store->contexts["library-a|work-existing"]
        );
        self::assertSame(1, $existing->version()->value());
        self::assertSame([], $store->events);
    }

    public function testInactiveInitialBookTypeRollsBackCompoundWrites(): void
    {
        [$service, , $store] = $this->fixture();

        try {
            $service->addWithNewWorkAndEdition(
                new LibraryId("library-a"),
                new ItemId("item-new"),
                new WorkId("work-new"),
                "New Work",
                new EditionId("edition-new"),
                $this->initialization("book-inactive")
            );
            self::fail("Inactive initial Book Type was accepted.");
        } catch (ValidationException $exception) {
            self::assertSame(
                FailureReason::ValidationFailed,
                $exception->reason()
            );
        }

        self::assertSame([], $store->works);
        self::assertSame([], $store->editions);
        self::assertSame([], $store->contexts);
        self::assertSame([], $store->items);
        self::assertSame([], $store->events);
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
            self::assertSame(0, $store->contextFindCount);
            self::assertSame([], $store->operations);
        }
    }

    public function testClassificationPermissionCannotAuthorizeItemAdd(): void
    {
        [$service, , $store, , $transaction] = $this->fixture(
            ManagementRole::Manager,
            UseAccess::Direct,
            MembershipStatus::Active,
            AdditionalPermissions::fromValues(
                AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
            )
        );

        try {
            $service->addForExistingEdition(
                new LibraryId("library-a"),
                new ItemId("item-new"),
                new EditionId("edition-secret")
            );
            self::fail("Classification management authorized Item-add.");
        } catch (AuthorizationException $exception) {
            self::assertSame(
                FailureReason::AuthorizationDenied,
                $exception->reason()
            );
            self::assertSame(0, $store->editionFindCount);
            self::assertSame(0, $store->workFindCount);
            self::assertSame(0, $store->contextFindCount);
            self::assertSame([], $store->operations);
            self::assertSame(0, $transaction->runCount);
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
                    new WorkId("work-existing"),
                    $this->initialization()
                );
            } else {
                $service->addWithNewWorkAndEdition(
                    new LibraryId("library-a"),
                    new ItemId("item-new"),
                    new WorkId("work-new"),
                    "New Work",
                    new EditionId("edition-new"),
                    $this->initialization()
                );
            }
            self::fail("Configured compound failure did not occur.");
        } catch (RuntimeException) {
            self::assertArrayNotHasKey("work-new", $store->works);
            self::assertArrayNotHasKey("edition-new", $store->editions);
            self::assertArrayNotHasKey("item-new", $store->items);
            self::assertSame([], $store->contexts);
            self::assertSame([], $store->events);
        }
    }

    public static function compoundFailureCases(): iterable
    {
        yield "new Edition fails at Edition" => ["new-edition", "edition"];
        yield "new Edition fails at Context" => ["new-edition", "context"];
        yield "new Edition fails at Item" => ["new-edition", "item"];
        yield "new Edition fails at Event" => ["new-edition", "event"];
        yield "new Work fails at Work" => ["new-work", "work"];
        yield "new Work fails at Edition" => ["new-work", "edition"];
        yield "new Work fails at Context" => ["new-work", "context"];
        yield "new Work fails at Item" => ["new-work", "item"];
        yield "new Work fails at Event" => ["new-work", "event"];
    }

    private function initialization(
        string $bookTypeId = "book-active"
    ): LibraryCatalogContextInitialization {
        return new LibraryCatalogContextInitialization(
            $this->selection($bookTypeId)
        );
    }

    private function selection(
        string $bookTypeId = "book-active"
    ): LibraryCatalogSelection {
        return new LibraryCatalogSelection(
            new LibraryBookTypeId($bookTypeId)
        );
    }

    private function fixture(
        ManagementRole $role = ManagementRole::Owner,
        UseAccess $useAccess = UseAccess::Direct,
        MembershipStatus $status = MembershipStatus::Active,
        ?AdditionalPermissions $permissions = null
    ): array {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("user-1");
        $actor = new ControllableAuthenticatedUser($userId);
        $store = new CatalogApplicationStore();
        $memberships = new CatalogApplicationMembershipRepository();
        $memberships->add(new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership($role, $useAccess, $status, $permissions)
        ));
        $transaction = new CatalogApplicationTransactionManager($store);
        $contexts = new CatalogApplicationContextRepository($store);
        $bookTypes = $this->createStub(LibraryBookTypeRepository::class);
        $bookTypes->method("findForUpdate")->willReturnCallback(
            static function (
                LibraryId $selectedLibraryId,
                LibraryBookTypeId $id
            ) use ($libraryId): ?LibraryBookType {
                if (
                    !$libraryId->equals($selectedLibraryId)
                    || !in_array(
                        $id->value(),
                        ["book-active", "book-inactive"],
                        true
                    )
                ) {
                    return null;
                }

                return new LibraryBookType(
                    $libraryId,
                    $id,
                    new ClassificationTermName("Reading book"),
                    new ClassificationNormalizedName("reading book"),
                    $id->value() === "book-active"
                        ? ClassificationTermStatus::Active
                        : ClassificationTermStatus::Inactive
                );
            }
        );
        $selectionResolver = new LibraryCatalogSelectionResolver(
            $bookTypes,
            $this->createStub(LibraryGenreRepository::class),
            $this->createStub(LibrarySubjectRepository::class)
        );
        $contextActivity = new LibraryCatalogContextActivity(
            new CatalogApplicationActivityFactory()
        );

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
                $contexts,
                new LibraryCatalogContextInitializer(
                    $contexts,
                    $selectionResolver,
                    new CatalogApplicationLibraryLock($store)
                ),
                $contextActivity,
                new CatalogApplicationActivityAppender($store),
                $transaction
            ),
            $actor,
            $store,
            $memberships,
            $transaction,
        ];
    }
}
