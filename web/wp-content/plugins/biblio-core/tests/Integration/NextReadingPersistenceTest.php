<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\NextReading\PreferredReadingSourceState;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntryNotAvailable,NextReadingListStale,NextReadingListVersion,NextReadingUndoUnavailable,NextReadingWorkUnavailable,PreferredReadingSourceType,PreferredReadingSourceUnavailable};

final class NextReadingPersistenceTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testGenericEntryContractAllowsDuplicatesAndKeepsOwnerPrivacy(): void
    {
        $actor = $this->createUser("next-owner");
        $other = $this->createUser("next-other");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();

        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $list = $application->nextReadingAdd()->addWithLibraryItem(
            new WorkId("next-work-1"),
            new LibraryId("next-library"),
            new ItemId("next-item")
        );
        $list = $application->nextReadingAdd()->addWithExternalLoan(
            new WorkId("next-work-1"),
            new ExternalLoanId("next-loan")
        );

        self::assertSame(5, $list->version()->value());
        self::assertCount(4, $list->entries());
        self::assertCount(4, array_unique(array_map(
            static fn ($entry): string => $entry->id()->value(),
            $list->entries()
        )));
        self::assertCount(3, $application->nextReadingHome()->get()->entries());
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));

        try {
            $application->nextReadingAdd()->add(new WorkId("unknown-work"));
            self::fail("Unavailable Work was accepted.");
        } catch (NextReadingWorkUnavailable) {
            self::addToAssertionCount(1);
        }

        foreach ([
            static fn () => $application->nextReadingAdd()->addWithLibraryItem(
                new WorkId("next-work-2"),
                new LibraryId("next-library"),
                new ItemId("next-item")
            ),
            static fn () => $application->nextReadingAdd()->addWithLibraryItem(
                new WorkId("next-work-1"),
                new LibraryId("foreign-library"),
                new ItemId("foreign-item")
            ),
            static fn () => $application->nextReadingAdd()->addWithExternalLoan(
                new WorkId("next-work-2"),
                new ExternalLoanId("next-loan")
            ),
        ] as $unavailableAdd) {
            try {
                $unavailableAdd();
                self::fail("Unavailable or mismatched preferred source was accepted.");
            } catch (PreferredReadingSourceUnavailable) {
                self::addToAssertionCount(1);
            }
        }

        $ids = array_map(static fn ($entry) => $entry->id(), $list->entries());
        $reordered = $application->nextReadingReorder()->reorder($list->version(), array_reverse($ids));
        self::assertSame(6, $reordered->version()->value());
        self::assertSame(6, $application->nextReadingReorder()->reorder(
            NextReadingListVersion::initial(),
            array_reverse($ids)
        )->version()->value());

        try {
            $application->nextReadingReorder()->reorder(new NextReadingListVersion(5), $ids);
            self::fail("Divergent stale reorder was accepted.");
        } catch (NextReadingListStale $stale) {
            self::assertSame(6, $stale->current()->version()->value());
        }

        self::assertSame(1, $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "next-library",
            "user_id" => (string) $other,
            "membership_status" => "active",
            "management_role" => "owner",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]));
        wp_set_current_user($other);
        self::assertCount(0, $application->myNextReadingList()->get()->entries());
        foreach ([
            static fn () => $application->nextReadingPreferredSource()->setLibraryItem(
                $ids[0],
                NextReadingListVersion::initial(),
                new LibraryId("next-library"),
                new ItemId("next-item")
            ),
            static fn () => $application->nextReadingRemove()->remove($ids[0], NextReadingListVersion::initial()),
        ] as $foreignMutation) {
            try {
                $foreignMutation();
                self::fail("A Library owner mutated another user's personal Next Reading entry.");
            } catch (NextReadingEntryNotAvailable) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
    }

    public function testPreferredSourceIsMutableAndLossPreservesSafeSnapshotProjection(): void
    {
        $actor = $this->createUser("next-source-owner");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $entryId = $list->entries()[0]->id();

        $list = $application->nextReadingPreferredSource()->setLibraryItem(
            $entryId,
            $list->version(),
            new LibraryId("next-library"),
            new ItemId("next-item")
        );
        $view = $application->myNextReadingList()->get()->entries()[0]->preferredSource();
        self::assertSame(PreferredReadingSourceType::LibraryItem, $view->type());
        self::assertSame(PreferredReadingSourceState::Available, $view->state());
        self::assertSame("Bibliotheekexemplaar", $view->label());

        self::assertSame(1, $this->database->delete($this->tableNames->items(), ["item_id" => "next-item"]));
        $afterLoss = $application->myNextReadingList()->get();
        self::assertSame($list->version()->value(), $afterLoss->version()->value());
        self::assertSame(1, $afterLoss->entries()[0]->position());
        self::assertSame(PreferredReadingSourceState::Unavailable, $afterLoss->entries()[0]->preferredSource()->state());
        self::assertSame("Voorkeursbron niet beschikbaar", $afterLoss->entries()[0]->preferredSource()->label());

        $cleared = $application->nextReadingPreferredSource()->clear($entryId, $list->version());
        self::assertNull($cleared->entries()[0]->preferredSource());
        self::assertSame(PreferredReadingSourceState::None, $application->myNextReadingList()->get()->entries()[0]->preferredSource()->state());
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
    }

    public function testRemovalCreatesOwnerScopedOneTimeUndoWithSameEntryIdentity(): void
    {
        $actor = $this->createUser("next-undo-owner");
        $other = $this->createUser("next-undo-other");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $entryId = $list->entries()[0]->id();
        $removal = $application->nextReadingRemove()->remove($entryId, $list->version());

        self::assertCount(0, $removal->list()->entries());
        self::assertSame(1, $this->tableCount($this->tableNames->nextReadingUndo()));
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
        self::assertSame(
            hash("sha256", $removal->undoToken()->value()),
            $this->database->get_var("SELECT undo_token_hash FROM `{$this->tableNames->nextReadingUndo()}`")
        );

        wp_set_current_user($other);
        try {
            $application->nextReadingUndo()->undo($removal->undoToken());
            self::fail("Another owner used the Undo token.");
        } catch (NextReadingUndoUnavailable) {
            self::addToAssertionCount(1);
        }

        wp_set_current_user($actor);
        $restored = $application->nextReadingUndo()->undo($removal->undoToken());
        self::assertSame($entryId->value(), $restored->entries()[0]->id()->value());
        self::assertSame(0, $this->tableCount($this->tableNames->nextReadingUndo()));

        $this->expectException(NextReadingUndoUnavailable::class);
        $application->nextReadingUndo()->undo($removal->undoToken());
    }

    public function testExpiredUndoIsUnavailableAndDoesNotRestoreEntry(): void
    {
        $actor = $this->createUser("next-undo-expiry");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $removal = $application->nextReadingRemove()->remove(
            $list->entries()[0]->id(),
            $list->version()
        );
        $this->database->update(
            $this->tableNames->nextReadingUndo(),
            [
                "created_at" => "2020-01-01 00:00:00.000000",
                "expires_at" => "2020-01-01 00:00:01.000000",
            ],
            ["undo_token_hash" => hash("sha256", $removal->undoToken()->value())]
        );

        try {
            $application->nextReadingUndo()->undo($removal->undoToken());
            self::fail("Expired Undo token restored an entry.");
        } catch (NextReadingUndoUnavailable) {
            self::assertCount(0, $application->myNextReadingList()->get()->entries());
        }
    }

    public function testInvalidFullReorderFailsAtomically(): void
    {
        $actor = $this->createUser("next-invalid-order");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-1"));
        $list = $application->nextReadingAdd()->add(new WorkId("next-work-2"));
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
            "library_name" => "Nextbibliotheek",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "next-library",
            "user_id" => (string) $actor,
            "membership_status" => "active",
            "management_role" => "member",
            "use_access" => "direct",
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
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "foreign-library",
            "library_name" => "Foreign Library",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "foreign-edition",
            "work_id" => "next-work-1",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "foreign-item",
            "library_id" => "foreign-library",
            "edition_id" => "foreign-edition",
            "item_status" => "active",
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
