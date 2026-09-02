<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\NextReading\RemoveNextReadingEntryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbNextReadingRepository,WpdbTransactionManager};
use Biblio\Core\Infrastructure\WordPress\SystemNextReadingClock;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingListVersion,NextReadingUndoToken,NextReadingUndoTokenGenerator};
use RuntimeException;

final class FixedConcurrencyUndoTokenGenerator implements NextReadingUndoTokenGenerator
{
    public function next(): NextReadingUndoToken
    {
        return new NextReadingUndoToken("undo-race-token");
    }
}

final class NextReadingConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testDifferentAndDuplicateConcurrentAddsSerialize(): void
    {
        $this->seedWorks(1, 2);
        $different = $this->race([
            ["add_work", "race-work-1", 1],
            ["add_work", "race-work-2", 1],
        ]);
        self::assertSame(["added", "added"], $this->statuses($different));
        self::assertSame(3, $this->version());
        self::assertSame([1, 2], $this->positions());

        $this->clearList();
        $duplicate = $this->race([
            ["add_work", "race-work-1", 1],
            ["add_work", "race-work-1", 1],
        ]);
        self::assertSame(["added", "added"], $this->statuses($duplicate));
        self::assertSame(3, $this->version());
        self::assertSame(2, $this->entryCount());
    }

    public function testAddReorderAndDeleteReorderRacesAreSerializable(): void
    {
        $this->seedWorks(1, 2, 3);
        $this->seedList(["race-entry-1", "race-entry-2"]);
        $addReorder = $this->race([
            ["add_work", "race-work-3", 3],
            ["reorder", "race-entry-2,race-entry-1", 3],
        ]);
        self::assertTrue(in_array($this->statuses($addReorder), [
            ["added", "reordered"],
            ["added", "stale"],
        ], true));
        self::assertSame(3, $this->entryCount());
        self::assertSame([1, 2, 3], $this->positions());
        self::assertContains($this->version(), [4, 5]);

        $this->clearList();
        $this->seedList(["race-entry-1", "race-entry-2", "race-entry-3"]);
        $deleteReorder = $this->race([
            ["remove", "race-entry-1", 4],
            ["reorder", "race-entry-3,race-entry-2,race-entry-1", 4],
        ]);
        self::assertTrue(in_array($this->statuses($deleteReorder), [
            ["removed", "stale"],
            ["reordered", "stale"],
        ], true));
        self::assertContains($this->entryCount(), [2, 3]);
        self::assertSame(range(1, $this->entryCount()), $this->positions());
        self::assertSame(5, $this->version());
    }

    public function testDivergentAndEqualConcurrentReordersUseOneSemanticWrite(): void
    {
        $this->seedWorks(1, 2, 3);
        $this->seedList(["race-entry-1", "race-entry-2", "race-entry-3"]);
        self::assertSame(4, $this->version(), $this->database->last_error);
        self::assertSame(3, $this->entryCount(), $this->database->last_error);
        $divergent = $this->race([
            ["reorder", "race-entry-3,race-entry-1,race-entry-2", 4],
            ["reorder", "race-entry-2,race-entry-3,race-entry-1", 4],
        ]);
        self::assertSame(["reordered", "stale"], $this->statuses($divergent), json_encode($divergent));
        self::assertSame(5, $this->version());
        self::assertSame([1, 2, 3], $this->positions());

        $this->clearList();
        $this->seedList(["race-entry-1", "race-entry-2", "race-entry-3"]);
        $equal = $this->race([
            ["reorder", "race-entry-3,race-entry-2,race-entry-1", 4],
            ["reorder", "race-entry-3,race-entry-2,race-entry-1", 4],
        ]);
        self::assertSame(["reordered", "reordered"], $this->statuses($equal));
        self::assertSame(5, $this->version());
        self::assertSame(["race-entry-3", "race-entry-2", "race-entry-1"], $this->orderedIds());
    }

    public function testSourceDeleteAgainstAddOrReorderNeverLosesEntryOrSnapshot(): void
    {
        $this->seedWorks(1);
        $this->seedItem();
        $addDelete = $this->race([
            ["add_item", "race-library,race-item", 1],
            ["delete_item", "race-item", 1],
        ]);
        self::assertTrue(in_array($this->statuses($addDelete), [
            ["added", "source_deleted"],
            ["source_deleted", "target_unavailable"],
        ], true));
        if ($this->entryCount() === 1) {
            self::assertNull($this->liveItem());
            self::assertSame("race-item", $this->snapshotItem());
        }

        $this->clearList();
        $this->seedItem();
        $this->seedItemEntry();
        $deleteReorder = $this->race([
            ["delete_item", "race-item", 2],
            ["reorder", "race-item-entry", 2],
        ]);
        self::assertSame(["reordered", "source_deleted"], $this->statuses($deleteReorder));
        self::assertSame(2, $this->version());
        self::assertSame(1, $this->entryCount());
        self::assertNull($this->liveItem());
        self::assertSame("race-item", $this->snapshotItem());
    }

    public function testRemoveStartAddReorderAndPreferenceStartRacesSerialize(): void
    {
        $this->seedWorks(1, 2);
        $this->seedItem();
        $this->seedList(["race-entry-1"]);
        $removeStart = $this->race([
            ["remove", "race-entry-1", 2],
            ["start_entry", "race-entry-1,race-library,race-item", 2],
        ]);
        self::assertTrue(in_array($this->statuses($removeStart), [
            ["not_available", "removed"],
            ["stale", "started"],
        ], true));
        self::assertSame(0, $this->entryCount());
        self::assertSame(3, $this->version());

        $this->database->query("DELETE FROM `{$this->tableNames->readingRounds()}`");
        $this->clearList();
        $this->seedList(["race-entry-1"]);
        $addStart = $this->race([
            ["add_work", "race-work-2", 2],
            ["start_item", "race-library,race-item", 2],
        ]);
        self::assertSame(["added", "started"], $this->statuses($addStart));
        self::assertSame(1, $this->entryCount());
        self::assertSame(4, $this->version());

        $this->database->query("DELETE FROM `{$this->tableNames->readingRounds()}`");
        $this->clearList();
        $this->seedList(["race-entry-1"]);
        $preferenceStart = $this->race([
            ["set_item", "race-entry-1,race-library,race-item", 2],
            ["start_item", "race-library,race-item", 2],
        ]);
        self::assertTrue(in_array($this->statuses($preferenceStart), [
            ["preferred", "started"],
            ["not_available", "started"],
        ], true));
        self::assertSame(0, $this->entryCount());
        self::assertContains($this->version(), [3, 4]);
    }

    public function testExternalStartReorderAndUndoRacesSerialize(): void
    {
        $this->seedWorks(1, 2, 3);
        $this->seedItem();
        $this->seedExternalLoan();
        $this->seedList(["race-entry-1", "race-entry-2"]);
        $externalReorder = $this->race([
            ["start_external", "race-loan", 3],
            ["reorder", "race-entry-2,race-entry-1", 3],
        ]);
        self::assertTrue(in_array($this->statuses($externalReorder), [
            ["reordered", "started"],
            ["stale", "started"],
        ], true));
        self::assertSame(1, $this->entryCount());
        self::assertContains($this->version(), [4, 5]);

        $this->database->query("DELETE FROM `{$this->tableNames->readingRounds()}`");
        $this->clearList();
        $this->seedList(["race-entry-1", "race-entry-2", "race-entry-3"]);
        $removal = $this->remove("race-entry-2", 4);
        $undoReorder = $this->race([
            ["undo", $removal->value(), 5],
            ["reorder", "race-entry-3,race-entry-1", 5],
        ]);
        self::assertTrue(in_array($this->statuses($undoReorder), [
            ["reordered", "undone"],
            ["stale", "undone"],
        ], true));
        self::assertSame(3, $this->entryCount());
        self::assertSame([1, 2, 3], $this->positions());

        $this->clearList();
        $this->seedList(["race-entry-1", "race-entry-2"]);
        $removal = $this->remove("race-entry-1", 3);
        $undoStart = $this->race([
            ["undo", $removal->value(), 4],
            ["start_item", "race-library,race-item", 4],
        ]);
        self::assertSame(["started", "undone"], $this->statuses($undoStart));
        self::assertContains($this->entryCount(), [1, 2]);
        if ($this->entryCount() === 1) {
            self::assertSame(6, $this->version());
            self::assertSame(["race-entry-2"], $this->orderedIds());
            self::assertSame([1], $this->positions());
        } else {
            self::assertSame(5, $this->version());
            self::assertSame(["race-entry-1", "race-entry-2"], $this->orderedIds());
            self::assertSame([1, 2], $this->positions());
        }
    }

    /** @param list<array{0:string,1:string,2:int}> $operations */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir() . "/biblio-next-race-" . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Next Reading race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];
        try {
            foreach ($operations as $index => [$action, $payload, $expected]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/NextReadingMutationWorker.php",
                    $action,
                    $payload,
                    "race-user",
                    (string) $expected,
                    $ready[$index],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Next Reading worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }
            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException("Next Reading worker exited early: " . stream_get_contents($worker["pipes"][2]));
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Next Reading workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release Next Reading workers.");
            }
            return [$this->finish($workers[0]), $this->finish($workers[1])];
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker["process"]) && proc_get_status($worker["process"])["running"]) {
                    proc_terminate($worker["process"]);
                    proc_close($worker["process"]);
                }
            }
            foreach ([...$ready, $release] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    private function finish(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Next Reading worker failed: {$error}");
        }
        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, "status");
        sort($statuses);
        return $statuses;
    }

    private function seedWorks(int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => "race-work-{$number}", "work_title" => "Race Work {$number}",
            ]);
        }
    }

    /** @param list<string> $entryIds */
    private function seedList(array $entryIds): void
    {
        $listResult = $this->database->insert($this->tableNames->nextReadingLists(), [
            "user_id" => "race-user", "list_version" => count($entryIds) + 1,
            "created_at" => "2026-08-23 10:00:00.000000", "updated_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%d", "%s", "%s"]);
        self::assertSame(1, $listResult, $this->database->last_error);
        foreach ($entryIds as $offset => $entryId) {
            $number = $offset + 1;
            $entryResult = $this->database->insert($this->tableNames->nextReadingEntries(), [
                "entry_id" => $entryId, "user_id" => "race-user", "work_id" => "race-work-{$number}",
                "preferred_source_type" => null, "preferred_source_id_snapshot" => null,
                "preferred_source_library_id_snapshot" => null, "item_id" => null,
                "external_loan_id" => null, "position" => $number,
                "created_at" => "2026-08-23 10:00:00.000000",
            ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]);
            self::assertSame(1, $entryResult, $this->database->last_error);
        }
    }

    private function seedItem(): void
    {
        $libraries = $this->tableNames->libraries();
        if ((int) $this->database->get_var("SELECT COUNT(*) FROM `{$libraries}` WHERE library_id='race-library'") === 0) {
            $this->database->insert($this->tableNames->libraries(), [
                "library_id" => "race-library", "library_name" => "Racebibliotheek", "library_type" => "private_library", "library_status" => "active",
            ]);
            $this->database->insert($this->tableNames->memberships(), [
                "library_id" => "race-library", "user_id" => "race-user", "membership_status" => "active",
                "management_role" => "member", "use_access" => "direct", "additional_permissions" => "[]",
            ], ["%s", "%s", "%s", "%s", "%s", "%s"]);
            $this->database->insert($this->tableNames->editions(), [
                "edition_id" => "race-edition", "work_id" => "race-work-1",
            ]);
        }
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "race-item", "library_id" => "race-library",
            "edition_id" => "race-edition", "item_status" => "active",
        ]);
    }

    private function seedExternalLoan(): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => "race-loan",
            "user_id" => "race-user",
            "work_id" => "race-work-1",
            "loan_status" => "active",
            "borrowed_at" => "2026-09-01 10:00:00.000000",
            "due_at" => null,
        ], ["%s", "%s", "%s", "%s", "%s", "%s"]), $this->database->last_error);
    }

    private function remove(string $entryId, int $expectedVersion): NextReadingUndoToken
    {
        $removal = (new RemoveNextReadingEntryService(
            new \Biblio\Core\Tests\Support\ControllableAuthenticatedUser(new UserId("race-user")),
            new WpdbNextReadingRepository($this->database, $this->tableNames),
            new SystemNextReadingClock(),
            new FixedConcurrencyUndoTokenGenerator(),
            new WpdbTransactionManager($this->database)
        ))->remove(
            new NextReadingEntryId($entryId),
            new NextReadingListVersion($expectedVersion)
        );
        return $removal->undoToken();
    }

    private function seedItemEntry(): void
    {
        $this->database->insert($this->tableNames->nextReadingLists(), [
            "user_id" => "race-user", "list_version" => 2,
            "created_at" => "2026-08-23 10:00:00.000000", "updated_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%d", "%s", "%s"]);
        $this->database->insert($this->tableNames->nextReadingEntries(), [
            "entry_id" => "race-item-entry", "user_id" => "race-user", "work_id" => "race-work-1",
            "preferred_source_type" => "library_item", "preferred_source_id_snapshot" => "race-item",
            "preferred_source_library_id_snapshot" => "race-library", "item_id" => "race-item",
            "external_loan_id" => null, "position" => 1,
            "created_at" => "2026-08-23 10:00:00.000000",
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]);
    }

    private function clearList(): void
    {
        $entries = $this->tableNames->nextReadingEntries();
        $lists = $this->tableNames->nextReadingLists();
        $this->database->query("DELETE FROM `{$entries}`");
        $this->database->query("DELETE FROM `{$lists}`");
    }

    private function version(): int
    {
        $table = $this->tableNames->nextReadingLists();
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT list_version FROM `{$table}` WHERE user_id=%s",
            "race-user"
        ));
    }

    private function entryCount(): int
    {
        $table = $this->tableNames->nextReadingEntries();
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE user_id=%s",
            "race-user"
        ));
    }

    private function positions(): array
    {
        $table = $this->tableNames->nextReadingEntries();
        return array_map("intval", $this->database->get_col("SELECT position FROM `{$table}` WHERE user_id='race-user' ORDER BY position"));
    }

    private function orderedIds(): array
    {
        $table = $this->tableNames->nextReadingEntries();
        return $this->database->get_col("SELECT entry_id FROM `{$table}` WHERE user_id='race-user' ORDER BY position");
    }

    private function liveItem(): ?string
    {
        $table = $this->tableNames->nextReadingEntries();
        $value = $this->database->get_var("SELECT item_id FROM `{$table}` WHERE user_id='race-user'");
        return is_string($value) ? $value : null;
    }

    private function snapshotItem(): string
    {
        $table = $this->tableNames->nextReadingEntries();
        return (string) $this->database->get_var("SELECT preferred_source_id_snapshot FROM `{$table}` WHERE user_id='race-user'");
    }
}
