<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanWriter;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;
use RuntimeException;

final class ReadingRoundConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testConcurrentStartsCreateExactlyOneActiveRound(): void
    {
        $user = new UserId("concurrent-reader");
        $library = new LibraryId("library-a");
        $item = $this->persistFixture($user, $library);
        $temporaryDirectory = sys_get_temp_dir()
            . "/biblio-reading-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }

        $releasePath = $temporaryDirectory . "/release";
        $readyPaths = [
            $temporaryDirectory . "/worker-a-ready",
            $temporaryDirectory . "/worker-b-ready",
        ];
        $workers = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $workers[] = $this->startWorker(
                    $user,
                    $library,
                    $item,
                    $readyPath,
                    $releasePath
                );
            }

            $this->awaitBothWorkers($workers, $readyPaths);

            if (file_put_contents($releasePath, "release") === false) {
                throw new RuntimeException("Could not release race barrier.");
            }

            $results = [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
            $statuses = array_column($results, "status");
            sort($statuses);

            self::assertSame(["conflict", "created"], $statuses);
            self::assertSame(1, $this->roundCount());
            self::assertNotNull((new WpdbReadingRoundRepository(
                $this->database,
                $this->tableNames
            ))->findActiveForUserAndSource(
                $user,
                ReadingSource::libraryItem($item->id())
            ));
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }

            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($temporaryDirectory);
        }
    }

    public function testConcurrentExternalLoanStartsCreateExactlyOneActiveRound(): void
    {
        $user = new UserId("concurrent-external-reader");
        $loan = $this->persistExternalLoanFixture($user);
        $temporaryDirectory = sys_get_temp_dir()
            . "/biblio-external-reading-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }

        $releasePath = $temporaryDirectory . "/release";
        $readyPaths = [
            $temporaryDirectory . "/worker-a-ready",
            $temporaryDirectory . "/worker-b-ready",
        ];
        $workers = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $workers[] = $this->startExternalLoanWorker(
                    $user,
                    $loan,
                    $readyPath,
                    $releasePath
                );
            }

            $this->awaitBothWorkers($workers, $readyPaths);

            if (file_put_contents($releasePath, "release") === false) {
                throw new RuntimeException("Could not release race barrier.");
            }

            $results = [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
            $statuses = array_column($results, "status");
            sort($statuses);

            self::assertSame(["conflict", "created"], $statuses);
            self::assertSame(1, $this->roundCount());
            self::assertNotNull((new WpdbReadingRoundRepository(
                $this->database,
                $this->tableNames
            ))->findActiveForUserAndSource(
                $user,
                ReadingSource::externalLoan($loan->id())
            ));
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }

            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($temporaryDirectory);
        }
    }

    private function persistFixture(UserId $user, LibraryId $library): Item
    {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ),
            new WpdbTransactionManager($this->database)
        ))->create(Library::privateLibrary($library), $user);
        $work = new Work(new WorkId("work-w"), "Concurrent Work");
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add($work);
        $edition = new Edition(new EditionId("edition-e"), $work->id());
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add($edition);
        $item = Item::active(new ItemId("item-a"), $library, $edition->id());
        (new WpdbItemRepository(
            $this->database,
            $this->tableNames
        ))->add($item);

        return $item;
    }

    private function persistExternalLoanFixture(UserId $user): ExternalLoan
    {
        $work = new Work(
            new WorkId("external-concurrent-work"),
            "External Concurrent Work"
        );
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add($work);
        $loan = ExternalLoan::active(
            new ExternalLoanId("external-concurrent-loan"),
            $user,
            $work->id(),
            new DateTimeImmutable("2026-08-17T09:00:00.000000+00:00")
        );
        (new WpdbExternalLoanWriter(
            $this->database,
            $this->tableNames
        ))->add($loan);

        return $loan;
    }

    private function startWorker(
        UserId $user,
        LibraryId $library,
        Item $item,
        string $readyPath,
        string $releasePath
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . "/Support/ReadingRoundStartWorker.php",
                $user->value(),
                $library->value(),
                $item->id()->value(),
                $readyPath,
                $releasePath,
            ],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start race worker.");
        }

        return ["process" => $process, "pipes" => $pipes];
    }

    private function startExternalLoanWorker(
        UserId $user,
        ExternalLoan $loan,
        string $readyPath,
        string $releasePath
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__
                    . "/Support/ExternalLoanReadingRoundStartWorker.php",
                $user->value(),
                $loan->id()->value(),
                $readyPath,
                $releasePath,
            ],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start race worker.");
        }

        return ["process" => $process, "pipes" => $pipes];
    }

    private function awaitBothWorkers(array $workers, array $readyPaths): void
    {
        $deadline = microtime(true) + 15;

        while (!is_file($readyPaths[0]) || !is_file($readyPaths[1])) {
            foreach ($workers as $worker) {
                $status = proc_get_status($worker["process"]);

                if (!$status["running"]) {
                    $error = stream_get_contents($worker["pipes"][2]);
                    throw new RuntimeException(
                        "Race worker exited before barrier: " . $error
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Workers did not reach the forced race window."
                );
            }

            usleep(10_000);
        }
    }

    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exitCode = proc_close($worker["process"]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Race worker failed with {$exitCode}: " . $error
            );
        }

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function terminateWorkerIfRunning(array $worker): void
    {
        if (!is_resource($worker["process"])) {
            return;
        }

        $status = proc_get_status($worker["process"]);

        if ($status["running"]) {
            proc_terminate($worker["process"]);
            proc_close($worker["process"]);
        }
    }

    private function roundCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        );
    }
}
