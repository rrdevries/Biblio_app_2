<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\{GetAccessibleLibraryItemService,LibraryAccessService};
use Biblio\Core\Application\NextReading\{AddNextReadingEntryService,NextReadingMutation};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbEditionRepository,WpdbExternalLoanRepository,WpdbItemRepository,WpdbLibraryMembershipRepository,WpdbNextReadingRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\SystemNextReadingClock;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingEntryIdCollisionExhausted,NextReadingEntryIdGenerator};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

final class RepeatingNextReadingIdGenerator implements NextReadingEntryIdGenerator
{
    private int $calls = 0;
    public function next(): NextReadingEntryId
    {
        $this->calls++;
        return new NextReadingEntryId("fixed-next-id");
    }
    public function calls(): int { return $this->calls; }
}

final class NextReadingIdRetryTest extends PersistenceIntegrationTestCase
{
    public function testOnlyPrimaryIdCollisionRetriesAndFourthRetryExhausts(): void
    {
        foreach ([1, 2] as $number) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => "retry-work-{$number}",
                "work_title" => "Retry Work {$number}",
            ]);
        }
        $actor = new ControllableAuthenticatedUser(new UserId("retry-user"));
        $repository = new WpdbNextReadingRepository($this->database, $this->tableNames);
        $ids = new RepeatingNextReadingIdGenerator();
        $service = new AddNextReadingEntryService(
            $actor,
            new WpdbWorkRepository($this->database, $this->tableNames),
            new GetAccessibleLibraryItemService(
                $actor,
                new WpdbItemRepository($this->database, $this->tableNames),
                new LibraryAccessService(
                    new WpdbLibraryMembershipRepository($this->database, $this->tableNames),
                    new LibraryAuthorizationPolicy()
                )
            ),
            new WpdbEditionRepository($this->database, $this->tableNames),
            new GetOwnedExternalLoanService(
                $actor,
                new WpdbExternalLoanRepository($this->database, $this->tableNames)
            ),
            new NextReadingMutation(
                $repository,
                $ids,
                new SystemNextReadingClock(),
                new WpdbTransactionManager($this->database)
            )
        );

        $first = $service->add(new WorkId("retry-work-1"));
        self::assertSame(2, $first->version()->value());
        self::assertSame(1, $ids->calls());

        try {
            $service->add(new WorkId("retry-work-2"));
            self::fail("Four colliding server IDs did not exhaust retry.");
        } catch (NextReadingEntryIdCollisionExhausted) {
            self::assertSame(5, $ids->calls());
        }

        $stored = $repository->findForUser(new UserId("retry-user"));
        self::assertCount(1, $stored->entries());
        self::assertSame(2, $stored->version()->value());
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        ));
    }
}
