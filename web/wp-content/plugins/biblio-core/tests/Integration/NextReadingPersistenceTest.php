<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\NextReading\NextReadingSourceStatus;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntryNotAvailable,NextReadingListStale,NextReadingListVersion,NextReadingTargetDuplicate,NextReadingTargetUnavailable};

final class NextReadingPersistenceTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testNamedServicesEnforceTargetsOrderingPrivacyHomeAndNoEvents(): void
    {
        $actor = $this->createUser("next-owner");
        $other = $this->createUser("next-other");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();

        $workList = $application->nextReadingWorkAdd()->add(new WorkId("next-work-1"));
        $itemList = $application->nextReadingItemAdd()->add(
            new LibraryId("next-library"),
            new ItemId("next-item")
        );
        $loanList = $application->nextReadingExternalLoanAdd()->add(
            new ExternalLoanId("next-loan")
        );
        $final = $application->nextReadingWorkAdd()->add(new WorkId("next-work-4"));

        self::assertSame(2, $workList->version()->value());
        self::assertSame(5, $final->version()->value());
        self::assertSame([1, 2, 3, 4], array_map(
            static fn ($entry): int => $entry->position()->value(),
            $final->entries()
        ));
        self::assertCount(3, $application->nextReadingHome()->get()->entries());
        self::assertSame(NextReadingSourceStatus::Unavailable, $application
            ->myNextReadingList()->get()->entries()[1]->sourceStatus());
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
        self::assertCount(4, array_unique(array_map(
            static fn ($entry): string => $entry->id()->value(),
            $final->entries()
        )));

        try {
            $application->nextReadingWorkAdd()->add(new WorkId("next-work-1"));
            self::fail("Duplicate Work target was accepted.");
        } catch (NextReadingTargetDuplicate) {
            self::addToAssertionCount(1);
        }
        foreach ([
            static fn () => $application->nextReadingItemAdd()->add(
                new LibraryId("next-library"),
                new ItemId("next-item")
            ),
            static fn () => $application->nextReadingExternalLoanAdd()->add(
                new ExternalLoanId("next-loan")
            ),
        ] as $duplicateAdd) {
            try {
                $duplicateAdd();
                self::fail("Duplicate concrete source was accepted.");
            } catch (NextReadingTargetDuplicate) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([
            static fn () => $application->nextReadingWorkAdd()->add(new WorkId("unknown-work")),
            static fn () => $application->nextReadingItemAdd()->add(
                new LibraryId("foreign-library"),
                new ItemId("next-item")
            ),
            static fn () => $application->nextReadingExternalLoanAdd()->add(
                new ExternalLoanId("foreign-loan")
            ),
        ] as $unavailableAdd) {
            try {
                $unavailableAdd();
                self::fail("Unavailable target was accepted.");
            } catch (NextReadingTargetUnavailable) {
                self::addToAssertionCount(1);
            }
        }

        $ids = array_map(static fn ($entry) => $entry->id(), $final->entries());
        $reordered = $application->nextReadingReorder()->reorder(
            $final->version(),
            array_reverse($ids)
        );
        self::assertSame(6, $reordered->version()->value());
        $noOp = $application->nextReadingReorder()->reorder(
            new NextReadingListVersion(1),
            array_reverse($ids)
        );
        self::assertSame(6, $noOp->version()->value());

        try {
            $application->nextReadingReorder()->reorder(
                new NextReadingListVersion(5),
                $ids
            );
            self::fail("Divergent stale reorder was accepted.");
        } catch (NextReadingListStale $stale) {
            self::assertSame(6, $stale->current()->version()->value());
        }

        $removed = $application->nextReadingRemove()->remove($ids[0], $reordered->version());
        self::assertSame(7, $removed->version()->value());
        self::assertSame([1, 2, 3], array_map(
            static fn ($entry): int => $entry->position()->value(),
            $removed->entries()
        ));

        wp_set_current_user($other);
        self::assertCount(0, $application->myNextReadingList()->get()->entries());
        try {
            $application->nextReadingReorder()->reorder(
                NextReadingListVersion::initial(),
                [$ids[1]]
            );
            self::fail("Foreign Entry was accepted in another owner's reorder.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(NextReadingEntryNotAvailable::class);
        $application->nextReadingRemove()->remove($ids[1], NextReadingListVersion::initial());
    }

    public function testSourceDeletionAndAccessLossPreserveSnapshotOrderAndVersion(): void
    {
        $actor = $this->createUser("next-source-owner");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $application->nextReadingItemAdd()->add(new LibraryId("next-library"), new ItemId("next-item"));
        $application->nextReadingExternalLoanAdd()->add(new ExternalLoanId("next-loan"));
        $before = $application->myNextReadingList()->get();

        $this->database->update(
            $this->tableNames->memberships(),
            ["membership_status" => "inactive"],
            ["library_id" => "next-library", "user_id" => (string) $actor]
        );
        $inaccessible = $application->myNextReadingList()->get();
        self::assertSame($before->version()->value(), $inaccessible->version()->value());
        self::assertSame(NextReadingSourceStatus::Inaccessible, $inaccessible->entries()[0]->sourceStatus());

        self::assertSame(1, $this->database->delete($this->tableNames->items(), ["item_id" => "next-item"]));
        self::assertSame(1, $this->database->delete($this->tableNames->externalLoans(), ["external_loan_id" => "next-loan"]));
        $after = $application->myNextReadingList()->get();

        self::assertSame($before->version()->value(), $after->version()->value());
        self::assertSame([1, 2], array_map(static fn ($entry) => $entry->position(), $after->entries()));
        self::assertSame([NextReadingSourceStatus::Missing, NextReadingSourceStatus::Missing], array_map(
            static fn ($entry) => $entry->sourceStatus(),
            $after->entries()
        ));
        self::assertSame(["next-item", "next-loan"], array_map(
            static fn ($entry) => $entry->sourceIdSnapshot(),
            $after->entries()
        ));
        self::assertSame(1, $this->tableCount($this->tableNames->works(), "work_id='next-work-1'"));
        self::assertSame(1, $this->tableCount($this->tableNames->works(), "work_id='next-work-2'"));
    }

    public function testInvalidFullReorderFailsAtomically(): void
    {
        $actor = $this->createUser("next-invalid-order");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $list = $application->nextReadingWorkAdd()->add(new WorkId("next-work-1"));
        $list = $application->nextReadingWorkAdd()->add(new WorkId("next-work-2"));
        $id = $list->entries()[0]->id();

        try {
            $application->nextReadingReorder()->reorder($list->version(), [$id, $id]);
            self::fail("Duplicate reorder set was accepted.");
        } catch (ValidationException) {
            $current = $application->myNextReadingList()->get();
            self::assertSame(3, $current->version()->value());
            self::assertSame([1, 2], array_map(static fn ($entry) => $entry->position(), $current->entries()));
        }
    }

    private function seed(int $actor): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "next-library",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "next-library",
            "user_id" => (string) $actor,
            "membership_status" => "active",
            "management_role" => "member",
            "use_access" => "view_only",
            "additional_permissions" => "[]",
        ]);
        foreach ([1, 2, 3, 4] as $number) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => "next-work-{$number}",
                "work_title" => "Next Work {$number}",
            ]);
        }
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "next-edition",
            "work_id" => "next-work-1",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "next-item",
            "library_id" => "next-library",
            "edition_id" => "next-edition",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => "next-loan",
            "user_id" => (string) $actor,
            "work_id" => "next-work-1",
            "loan_status" => "active",
            "borrowed_at" => "2026-08-23 10:00:00.000000",
            "due_at" => null,
        ]);
    }

    private function createUser(string $prefix): int
    {
        $id = wp_insert_user([
            "user_login" => $prefix . "-" . bin2hex(random_bytes(4)),
            "user_pass" => "integration-only",
        ]);
        self::assertIsInt($id);
        return (int) $id;
    }

    private function tableCount(string $table, string $where = "1=1"): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
    }
}
