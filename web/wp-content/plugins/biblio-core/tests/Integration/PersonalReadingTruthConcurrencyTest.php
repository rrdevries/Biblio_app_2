<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use DateTimeImmutable;
use RuntimeException;

final class PersonalReadingTruthConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testDivergentConcurrentWritesSerializeWithoutDuplicates(): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => "truth-race-work", "work_title" => "Truth race Work"]
        ));

        $results = $this->race([
            "read_known_date_unknown",
            "unknown",
        ]);
        $versions = array_column($results, "version");
        sort($versions);

        self::assertSame([1, 2], $versions);
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->personalReadingTruths()}` "
                . "WHERE user_id='truth-race-user' AND work_id='truth-race-work'"
        ));
        self::assertContains((string) $this->database->get_var(
            "SELECT truth_state FROM `{$this->tableNames->personalReadingTruths()}` "
                . "WHERE user_id='truth-race-user' AND work_id='truth-race-work'"
        ), ["read_known_date_unknown", "unknown"]);
    }

    public function testExplicitNotReadSeesCompletedRoundCommittedWhileWaitingForSharedLock(): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => "truth-round-race-work", "work_title" => "Round race Work"]
        ));
        $user = new UserId("truth-round-race-user");
        $round = ReadingRound::legacyActive(
            new ReadingRoundId("truth-round-race-round"),
            $user,
            new WorkId("truth-round-race-work"),
            null,
            new DateTimeImmutable("2026-09-08T10:00:00.000000+00:00")
        );
        $rounds = new WpdbReadingRoundRepository(
            $this->database,
            $this->tableNames
        );
        (new WpdbTransactionManager($this->database))->run(
            function () use ($rounds, $user, $round): void {
                $rounds->addForUser($user, $round);
            }
        );

        $directory = sys_get_temp_dir() . "/biblio-truth-round-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create truth/round race directory.");
        }
        $roundReady = $directory . "/round-ready";
        $roundRelease = $directory . "/round-release";
        $truthReady = $directory . "/truth-ready";
        $truthRelease = $directory . "/truth-release";
        $truthLockAttempt = $directory . "/truth-lock-attempt";
        $roundWorker = null;
        $truthWorker = null;

        try {
            $roundWorker = $this->startWorker(
                __DIR__ . "/Support/PersonalReadingTruthRoundWorker.php",
                [
                    "truth-round-race-round",
                    "truth-round-race-user",
                    "truth-round-race-work",
                    $roundReady,
                    $roundRelease,
                ]
            );
            $this->awaitFile($roundReady, [$roundWorker]);

            $truthWorker = $this->startWorker(
                __DIR__ . "/Support/PersonalReadingTruthWorker.php",
                [
                    "explicit_not_read",
                    "truth-round-race-user",
                    "truth-round-race-work",
                    $truthReady,
                    $truthRelease,
                    $truthLockAttempt,
                ]
            );
            self::assertSame(7, file_put_contents($truthRelease, "release"));
            $this->awaitFile($truthLockAttempt, [$roundWorker, $truthWorker]);
            self::assertSame(7, file_put_contents($roundRelease, "release"));

            self::assertSame("completed", $this->finish($roundWorker)["status"]);
            self::assertSame("contradiction", $this->finish($truthWorker)["status"]);
            $roundWorker = null;
            $truthWorker = null;

            self::assertSame("completed", (string) $this->database->get_var(
                "SELECT round_outcome FROM `{$this->tableNames->readingRounds()}` "
                    . "WHERE reading_round_id='truth-round-race-round'"
            ));
            self::assertSame(0, (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->personalReadingTruths()}` "
                    . "WHERE user_id='truth-round-race-user' "
                    . "AND work_id='truth-round-race-work'"
            ));
        } finally {
            foreach ([$roundWorker, $truthWorker] as $worker) {
                if (is_array($worker) && is_resource($worker["process"])) {
                    proc_terminate($worker["process"]);
                    proc_close($worker["process"]);
                }
            }
            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /** @param list<string> $states */
    private function race(array $states): array
    {
        $directory = sys_get_temp_dir() . "/biblio-truth-race-" . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create truth race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($states as $index => $state) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/PersonalReadingTruthWorker.php",
                    $state,
                    "truth-race-user",
                    "truth-race-work",
                    $ready[$index],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start truth worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Truth worker exited early: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Truth workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release truth workers.");
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

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finish(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Truth worker failed: {$error}");
        }

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startWorker(string $script, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, ...$arguments],
            [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start concurrency worker.");
        }

        return ["process" => $process, "pipes" => $pipes];
    }

    /** @param list<array{process:resource,pipes:array<int,resource>}> $workers */
    private function awaitFile(string $path, array $workers): void
    {
        $deadline = microtime(true) + 15;
        while (!is_file($path)) {
            foreach ($workers as $worker) {
                if (!proc_get_status($worker["process"])["running"]) {
                    throw new RuntimeException(
                        "Concurrency worker exited early: "
                            . stream_get_contents($worker["pipes"][2])
                    );
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Concurrency worker missed barrier.");
            }
            usleep(10_000);
        }
    }
}
