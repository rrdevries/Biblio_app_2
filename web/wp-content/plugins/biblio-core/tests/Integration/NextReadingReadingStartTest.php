<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\NextReadingListStale;
use Biblio\Core\Reading\ReadingDate;
use Throwable;

final class NextReadingReadingStartTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testStartConsumesAtMostOneExactThenGeneralOrExplicitEntry(): void
    {
        $actor = $this->createUser("next-reading-owner");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();

        $list = $application->nextReadingAdd()->add(new WorkId("start-work"));
        $generalId = $list->entries()[0]->id();
        $list = $application->nextReadingAdd()->addWithLibraryItem(
            new WorkId("start-work"),
            new LibraryId("start-library"),
            new ItemId("start-item-b")
        );
        $exactId = $list->entries()[1]->id();
        $list = $application->nextReadingAdd()->addWithLibraryItem(
            new WorkId("start-work"),
            new LibraryId("start-library"),
            new ItemId("start-item-b")
        );

        $application->libraryItemReading()->start(
            new LibraryId("start-library"),
            new ItemId("start-item-b"),
            ReadingDate::exact(2026, 9, 2)
        );
        $afterAutomatic = $application->myNextReadingList()->get();
        $afterAutomaticIds = array_map(
            static fn ($entry): string => $entry->id()->value(),
            $afterAutomatic->entries()
        );
        self::assertCount(2, $afterAutomatic->entries());
        self::assertNotContains($exactId->value(), $afterAutomaticIds);
        self::assertContains($generalId->value(), $afterAutomaticIds);
        self::assertSame(0, $this->tableCount($this->tableNames->nextReadingUndo()));
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
        try {
            $application->nextReadingReorder()->reorder(
                $list->version(),
                array_reverse(array_map(
                    static fn ($entry) => $entry->id(),
                    $afterAutomatic->entries()
                ))
            );
            self::fail("Stale manual reorder after automatic consumption was accepted.");
        } catch (NextReadingListStale $stale) {
            self::assertSame(5, $stale->current()->version()->value());
        }

        $application->nextReadingEntryReading()->withLibraryItem(
            $generalId,
            new LibraryId("start-library"),
            new ItemId("start-item-c"),
            ReadingDate::exact(2026, 9, 2)
        );
        $afterExplicit = $application->myNextReadingList()->get();
        self::assertCount(1, $afterExplicit->entries());
        self::assertNotContains(
            $generalId->value(),
            array_map(static fn ($entry): string => $entry->id()->value(), $afterExplicit->entries())
        );
        self::assertSame(0, $this->tableCount($this->tableNames->nextReadingUndo()));
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));

        $application->libraryItemReading()->start(
            new LibraryId("start-library"),
            new ItemId("start-item-a"),
            ReadingDate::exact(2026, 9, 2)
        );
        self::assertCount(1, $application->myNextReadingList()->get()->entries());
        self::assertSame(3, $this->roundCount());
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
    }

    public function testConsumptionFailureRollsBackRoundAndListTogether(): void
    {
        $actor = $this->createUser("next-reading-rollback");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();
        $application->nextReadingAdd()->add(new WorkId("start-work"));
        $trigger = "biblio_test_reject_next_entry_delete";
        $entries = $this->tableNames->nextReadingEntries();
        $this->database->query(
            "CREATE TRIGGER `{$trigger}` BEFORE DELETE ON `{$entries}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced list failure'"
        );
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            try {
                $application->libraryItemReading()->start(
                    new LibraryId("start-library"),
                    new ItemId("start-item-a"),
                    ReadingDate::exact(2026, 9, 2)
                );
                self::fail("Forced Next Reading write failure was accepted.");
            } catch (Throwable) {
                self::assertSame(0, $this->roundCount());
                self::assertCount(1, $application->myNextReadingList()->get()->entries());
            }
        } finally {
            $this->database->suppress_errors($previousSuppression);
            $this->database->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
    }

    public function testNoMatchCreatesRoundWithoutPersistingVirtualListState(): void
    {
        $actor = $this->createUser("next-reading-no-list");
        $this->seed($actor);
        wp_set_current_user($actor);
        $application = (new ProductionComposition($this->database))->application();

        $application->libraryItemReading()->start(
            new LibraryId("start-library"),
            new ItemId("start-item-a"),
            ReadingDate::exact(2026, 9, 2)
        );

        self::assertSame(1, $this->roundCount());
        self::assertCount(0, $application->myNextReadingList()->get()->entries());
        self::assertSame(0, (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->nextReadingLists()}` WHERE user_id=%s",
            (string) $actor
        )));
        self::assertSame(0, $this->tableCount($this->tableNames->nextReadingUndo()));
        self::assertSame(0, $this->tableCount($this->tableNames->libraryActivityEvents()));
    }

    private function seed(int $actor): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "start-library",
            "library_name" => "Startbibliotheek",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "start-library",
            "user_id" => (string) $actor,
            "membership_status" => "active",
            "management_role" => "member",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "start-work",
            "work_title" => "Start Work",
        ]);
        foreach (["a", "b", "c"] as $suffix) {
            $this->database->insert($this->tableNames->editions(), [
                "edition_id" => "start-edition-{$suffix}",
                "work_id" => "start-work",
            ]);
            $this->database->insert($this->tableNames->items(), [
                "item_id" => "start-item-{$suffix}",
                "library_id" => "start-library",
                "edition_id" => "start-edition-{$suffix}",
                "item_status" => "active",
            ]);
        }
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

    private function roundCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        );
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
