<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use RuntimeException;

final class ClassificationSeedConcurrencyTest extends
    PersistenceIntegrationTestCase
{
    public function testParallelBootstrapConvergesWithoutDuplicates(): void
    {
        $libraryId = new LibraryId("library-seed-race");
        (new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        ))->add(Library::privateLibrary($libraryId));
        $directory = sys_get_temp_dir()
            . "/biblio-seed-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create seed race directory.");
        }

        $releasePath = $directory . "/release";
        $workers = [];

        try {
            $workers = [
                $this->startWorker(
                    $libraryId,
                    $directory . "/ready-a",
                    $releasePath
                ),
                $this->startWorker(
                    $libraryId,
                    $directory . "/ready-b",
                    $releasePath
                ),
            ];
            $this->awaitWorkers($workers, $directory);

            if (file_put_contents($releasePath, "release") === false) {
                throw new RuntimeException("Could not release seed race.");
            }

            self::assertSame("ok", $this->finishWorker($workers[0]));
            self::assertSame("ok", $this->finishWorker($workers[1]));
            self::assertSame(9, $this->termCount(
                $this->tableNames->libraryBookTypes(),
                $libraryId
            ));
            self::assertSame(12, $this->termCount(
                $this->tableNames->libraryGenres(),
                $libraryId
            ));
            self::assertSame(9, $this->distinctSeedCount(
                $this->tableNames->libraryBookTypes(),
                $libraryId
            ));
            self::assertSame(12, $this->distinctSeedCount(
                $this->tableNames->libraryGenres(),
                $libraryId
            ));
            self::assertSame(0, $this->termCount(
                $this->tableNames->librarySubjects(),
                $libraryId
            ));
            self::assertSame(0, $this->termCount(
                $this->tableNames->libraryActivityEvents(),
                $libraryId
            ));
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }

            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($directory);
        }
    }

    private function startWorker(
        LibraryId $libraryId,
        string $readyPath,
        string $releasePath
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . "/Support/ClassificationSeedBootstrapWorker.php",
                $libraryId->value(),
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
            throw new RuntimeException("Could not start seed race worker.");
        }

        return ["process" => $process, "pipes" => $pipes];
    }

    private function awaitWorkers(array $workers, string $directory): void
    {
        $deadline = microtime(true) + 15;

        while (count(glob($directory . "/ready-*") ?: []) < 2) {
            foreach ($workers as $worker) {
                $status = proc_get_status($worker["process"]);

                if (!$status["running"]) {
                    $error = stream_get_contents($worker["pipes"][2]);
                    throw new RuntimeException(
                        "Seed worker exited before barrier: " . $error
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Seed workers did not reach the race barrier."
                );
            }

            usleep(10_000);
        }
    }

    private function finishWorker(array $worker): string
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exitCode = proc_close($worker["process"]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Seed race worker failed with {$exitCode}: " . $error
            );
        }

        return trim($output);
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

    private function termCount(string $table, LibraryId $libraryId): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE library_id = %s",
            $libraryId->value()
        ));
    }

    private function distinctSeedCount(
        string $table,
        LibraryId $libraryId
    ): int {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(DISTINCT seed_key) FROM `{$table}` "
            . "WHERE library_id = %s",
            $libraryId->value()
        ));
    }
}
