<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use RuntimeException;
use WP_Error;

final class CatalogCreationConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testConcurrentIdenticalCreationHasOneWinnerAndOneConflict(): void
    {
        $wordpressUserId = $this->createWordPressUser("catalog-race-owner");
        $libraryId = new LibraryId("library-race");
        $this->createOwnedLibrary($libraryId, $wordpressUserId);
        $itemId = new ItemId("item-race");
        $workId = new WorkId("work-race");
        $editionId = new EditionId("edition-race");
        $temporaryDirectory = sys_get_temp_dir()
            . "/biblio-catalog-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }

        $workers = [];

        try {
            $workers = [
                $this->startWorker(
                    $wordpressUserId,
                    $libraryId,
                    $itemId,
                    $workId,
                    $editionId,
                    $temporaryDirectory
                ),
                $this->startWorker(
                    $wordpressUserId,
                    $libraryId,
                    $itemId,
                    $workId,
                    $editionId,
                    $temporaryDirectory
                ),
            ];
            $this->awaitWorkers($workers, $temporaryDirectory);

            if (file_put_contents(
                $temporaryDirectory . "/release",
                "release"
            ) === false) {
                throw new RuntimeException("Could not release race barrier.");
            }

            $results = [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
            $statuses = array_column($results, "status");
            sort($statuses);

            self::assertSame(["conflict", "created"], $statuses);
            $conflicts = array_values(array_filter(
                $results,
                static fn (array $result): bool =>
                    $result["status"] === "conflict"
            ));
            self::assertSame(
                FailureReason::CatalogRecordAlreadyExists->value,
                $conflicts[0]["reason"]
            );

            $work = (new WpdbWorkRepository(
                $this->database,
                $this->tableNames
            ))->find($workId);
            $edition = (new WpdbEditionRepository(
                $this->database,
                $this->tableNames
            ))->find($editionId);
            $item = (new WpdbItemRepository(
                $this->database,
                $this->tableNames
            ))->findInLibrary($itemId, $libraryId);

            self::assertNotNull($work);
            self::assertNotNull($edition);
            self::assertNotNull($item);
            self::assertTrue($work->id()->equals($edition->workId()));
            self::assertTrue($edition->id()->equals($item->editionId()));
            self::assertSame(1, $this->tableCount($this->tableNames->works()));
            self::assertSame(1, $this->tableCount($this->tableNames->editions()));
            self::assertSame(1, $this->tableCount($this->tableNames->items()));
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }

            foreach (glob($temporaryDirectory . "/*") ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($temporaryDirectory);
        }
    }

    public function testConcurrentEquivalentIsbnCreationReusesOneEdition(): void
    {
        $wordpressUserId = $this->createWordPressUser("catalog-isbn-race-owner");
        $libraryId = new LibraryId("library-isbn-race");
        $this->createOwnedLibrary($libraryId, $wordpressUserId);
        $temporaryDirectory = sys_get_temp_dir()
            . "/biblio-isbn-race-" . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException("Could not create ISBN race directory.");
        }
        $workers = [];

        try {
            $workers = [
                $this->startWorker(
                    $wordpressUserId,
                    $libraryId,
                    new ItemId("item-isbn-a"),
                    new WorkId("work-isbn-a"),
                    new EditionId("edition-isbn-a"),
                    $temporaryDirectory,
                    "0-306-40615-2"
                ),
                $this->startWorker(
                    $wordpressUserId,
                    $libraryId,
                    new ItemId("item-isbn-b"),
                    new WorkId("work-isbn-b"),
                    new EditionId("edition-isbn-b"),
                    $temporaryDirectory,
                    "9780306406157"
                ),
            ];
            $this->awaitWorkers($workers, $temporaryDirectory);
            file_put_contents($temporaryDirectory . "/release", "release");
            $results = [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];

            self::assertSame(["created", "created"], array_column($results, "status"));
            self::assertSame(1, $this->tableCount($this->tableNames->works()));
            self::assertSame(1, $this->tableCount($this->tableNames->editions()));
            self::assertSame(2, $this->tableCount($this->tableNames->items()));
            self::assertSame(
                1,
                $this->tableCount($this->tableNames->editionIdentifierClaims())
            );
            self::assertSame(
                1,
                (int) $this->database->get_var(
                    "SELECT COUNT(DISTINCT edition_id) FROM `{$this->tableNames->items()}`"
                )
            );
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }
            foreach (glob($temporaryDirectory . "/*") ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($temporaryDirectory);
        }
    }

    private function startWorker(
        int $wordpressUserId,
        LibraryId $libraryId,
        ItemId $itemId,
        WorkId $workId,
        EditionId $editionId,
        string $barrierDirectory,
        ?string $isbnInput = null
    ): array {
        $pipes = [];
        $command = [
                PHP_BINARY,
                __DIR__ . "/Support/CatalogItemCreationWorker.php",
                (string) $wordpressUserId,
                $libraryId->value(),
                $itemId->value(),
                $workId->value(),
                $editionId->value(),
                $barrierDirectory,
        ];
        if ($isbnInput !== null) {
            $command[] = $isbnInput;
        }
        $process = proc_open(
            $command,
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start catalog race worker.");
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
                        "Catalog worker exited before barrier: " . $error
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Catalog workers did not reach the race barrier."
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
                "Catalog race worker failed with {$exitCode}: " . $error
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

    private function createOwnedLibrary(
        LibraryId $libraryId,
        int $wordpressUserId
    ): void {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ),
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        ))->create(
            Library::privateLibrary($libraryId),
            new UserId((string) $wordpressUserId)
        );
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
        ]);

        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);

        return $result;
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
