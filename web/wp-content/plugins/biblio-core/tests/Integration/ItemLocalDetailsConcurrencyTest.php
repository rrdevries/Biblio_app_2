<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use RuntimeException;

final class ItemLocalDetailsConcurrencyTest extends PersistenceIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "details-race-library",
            "library_name" => "Details race",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "details-race-work",
            "work_title" => "Details race",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "details-race-edition",
            "work_id" => "details-race-work",
            "edition_title" => "Details race",
        ]);
        foreach (["equal", "divergent", "clear"] as $suffix) {
            self::assertSame(1, $this->database->insert(
                $this->tableNames->items(),
                [
                    "library_id" => "details-race-library",
                    "item_id" => "details-race-{$suffix}",
                    "edition_id" => "details-race-edition",
                    "item_status" => "active",
                    "item_version" => 1,
                ]
            ));
        }
    }

    public function testConcurrentCreateAndUpdateNeverSilentlyOverwrite(): void
    {
        $equal = $this->race("details-race-equal", [
            ["goed", "-"],
            ["goed", "-"],
        ]);
        self::assertSame(["success", "success"], array_column($equal, "status"));
        self::assertSame(
            ["goed", "1", "1"],
            $this->stored("details-race-equal")
        );

        $divergent = $this->race("details-race-divergent", [
            ["goed", "-"],
            ["slecht", "-"],
        ]);
        $statuses = array_column($divergent, "status");
        sort($statuses);
        self::assertSame(["stale", "success"], $statuses);
        self::assertContains(
            $this->stored("details-race-divergent"),
            [["goed", "1", "1"], ["slecht", "1", "1"]]
        );

        $updated = $this->race("details-race-equal", [
            ["redelijk", "1"],
            ["matig", "1"],
        ]);
        $statuses = array_column($updated, "status");
        sort($statuses);
        self::assertSame(["stale", "success"], $statuses);
        self::assertContains(
            $this->stored("details-race-equal"),
            [["redelijk", "2", "1"], ["matig", "2", "1"]]
        );

        $created = $this->race("details-race-clear", [
            ["goed", "-"],
            ["goed", "-"],
        ]);
        self::assertSame(["success", "success"], array_column($created, "status"));
        $clearVersusUpdate = $this->race("details-race-clear", [
            ["unknown", "1"],
            ["slecht", "1"],
        ]);
        $statuses = array_column($clearVersusUpdate, "status");
        sort($statuses);
        self::assertSame(["stale", "success"], $statuses);
        self::assertContains(
            $this->stored("details-race-clear"),
            [["", "2", "1"], ["slecht", "2", "1"]]
        );
    }

    /**
     * @param list<array{string,string}> $operations
     * @return list<array<string,mixed>>
     */
    private function race(string $itemId, array $operations): array
    {
        $directory = sys_get_temp_dir() . "/biblio-details-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Item-local race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($operations as $offset => [$condition, $expected]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/ItemLocalDetailsMutationWorker.php",
                    "details-race-library",
                    $itemId,
                    $condition,
                    $expected,
                    $ready[$offset],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Item-local race worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Item-local race worker exited early: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Item-local race workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release Item-local workers.");
            }

            return array_map($this->finishWorker(...), $workers);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker["process"])) {
                    $status = proc_get_status($worker["process"]);
                    if ($status["running"]) {
                        proc_terminate($worker["process"]);
                        proc_close($worker["process"]);
                    }
                }
            }
            foreach ([...$ready, $release] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Item-local race worker failed: {$error}");
        }
        $result = json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException("Item-local race worker returned invalid JSON.");
        }
        return $result;
    }

    /** @return array{string,string,string} */
    private function stored(string $itemId): array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT condition_code,details_version,COUNT(*) AS row_count "
                . "FROM `{$this->tableNames->itemLocalDetails()}` "
                . "WHERE library_id='details-race-library' AND item_id=%s",
            $itemId
        ), ARRAY_N);
        if (!is_array($row)) {
            throw new RuntimeException("Item-local race row is missing.");
        }
        return [(string) $row[0], (string) $row[1], (string) $row[2]];
    }
}
