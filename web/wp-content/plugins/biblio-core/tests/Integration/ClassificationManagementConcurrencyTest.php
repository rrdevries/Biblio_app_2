<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use JsonException;
use RuntimeException;
use WP_Error;

final class ClassificationManagementConcurrencyTest extends
    PersistenceIntegrationTestCase
{
    public function testConcurrentContextSavesHaveOneWinnerAndOneStaleConflict(): void
    {
        [$ownerId, $libraryId] = $this->libraryFixture("context-save-race");
        $workId = $this->addRepresentedWork($libraryId, "work-save");
        $book = $this->seedBookType($libraryId, "book_type.reading_book");
        $genreA = $this->seedGenre($libraryId, "genre.fantasy");
        $genreB = $this->seedGenre($libraryId, "genre.thriller");
        wp_set_current_user($ownerId);
        (new ProductionComposition($this->database))->application()
            ->catalogContextCreation()->createForRepresentedWork(
                $libraryId,
                $workId,
                new LibraryCatalogSelection($book)
            );
        $eventsBefore = $this->eventCount($libraryId);
        $base = $this->contextPayload($ownerId, $libraryId, $workId, $book);
        $results = $this->race(
            array_merge($base, [
                "operation" => "context_save",
                "genre_ids" => [$genreA->value()],
                "expected_version" => 1,
            ]),
            array_merge($base, [
                "operation" => "context_save",
                "genre_ids" => [$genreB->value()],
                "expected_version" => 1,
            ])
        );

        self::assertSame(["stale", "success"], $this->statuses($results));
        self::assertSame(2, $this->contexts()
            ->find($libraryId, $workId)?->version()->value());
        self::assertSame($eventsBefore + 1, $this->eventCount($libraryId));
    }

    public function testConcurrentContextCreateIsIdempotentOrConflictingByDesiredState(): void
    {
        [$ownerId, $libraryId] = $this->libraryFixture("context-create-race");
        $bookA = $this->seedBookType($libraryId, "book_type.reading_book");
        $bookB = $this->seedBookType($libraryId, "book_type.cookbook");
        $equalWork = $this->addRepresentedWork($libraryId, "work-equal");
        $differentWork = $this->addRepresentedWork(
            $libraryId,
            "work-different"
        );
        $equal = $this->contextPayload(
            $ownerId,
            $libraryId,
            $equalWork,
            $bookA
        ) + ["operation" => "context_create"];
        $results = $this->race($equal, $equal);
        self::assertSame(["success", "success"], $this->statuses($results));
        self::assertSame(1, $this->eventCount($libraryId));

        $base = $this->contextPayload(
            $ownerId,
            $libraryId,
            $differentWork,
            $bookA
        ) + ["operation" => "context_create"];
        $different = $base;
        $different["book_type_id"] = $bookB->value();
        $results = $this->race($base, $different);
        self::assertSame(
            ["context_conflict", "success"],
            $this->statuses($results)
        );
        self::assertSame(2, $this->eventCount($libraryId));
    }

    public function testConcurrentItemAddsShareOrConflictOnInitialContext(): void
    {
        [$ownerId, $libraryId] = $this->libraryFixture("item-context-race");
        $bookA = $this->seedBookType($libraryId, "book_type.reading_book");
        $bookB = $this->seedBookType($libraryId, "book_type.cookbook");
        $workRepository = new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        );
        $equalWork = new WorkId("work-item-equal");
        $differentWork = new WorkId("work-item-different");
        $workRepository->add(new Work($equalWork, "Equal Item Work"));
        $workRepository->add(new Work(
            $differentWork,
            "Different Item Work"
        ));

        $equal = $this->itemAddPayload(
            $ownerId,
            $libraryId,
            $equalWork,
            $bookA,
            "equal"
        );
        $results = $this->race(
            array_merge($equal, [
                "item_id" => "item-equal-a",
                "edition_id" => "edition-equal-a",
            ]),
            array_merge($equal, [
                "item_id" => "item-equal-b",
                "edition_id" => "edition-equal-b",
            ]),
            $libraryId
        );

        self::assertSame(["success", "success"], $this->statuses($results));
        self::assertSame(
            $bookA->value(),
            $this->contexts()->find($libraryId, $equalWork)
                ?->classification()->bookTypeId()->value()
        );
        self::assertSame(1, $this->eventCount($libraryId));

        $first = $this->itemAddPayload(
            $ownerId,
            $libraryId,
            $differentWork,
            $bookA,
            "different-a"
        );
        $second = $this->itemAddPayload(
            $ownerId,
            $libraryId,
            $differentWork,
            $bookB,
            "different-b"
        );
        $results = $this->race($first, $second, $libraryId);

        self::assertSame(
            ["context_conflict", "success"],
            $this->statuses($results)
        );
        $storedBookType = $this->contexts()
            ->find($libraryId, $differentWork)
            ?->classification()->bookTypeId()->value();
        self::assertContains(
            $storedBookType,
            [$bookA->value(), $bookB->value()]
        );
        self::assertSame(2, $this->eventCount($libraryId));
        self::assertSame(3, $this->tableCount($this->tableNames->editions()));
        self::assertSame(3, $this->tableCount($this->tableNames->items()));
        self::assertSame(2, $this->tableCount(
            $this->tableNames->libraryCatalogContexts()
        ));
    }

    public function testConcurrentDuplicateCreateAndRenameCreateHaveSingleAuditedWinner(): void
    {
        [$ownerId, $libraryId] = $this->libraryFixture("term-race");
        $base = [
            "operation" => "genre_create",
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "name" => "Race Genre",
        ];
        $results = $this->race(
            $base + ["term_id" => "genre-race-a"],
            $base + ["term_id" => "genre-race-b"]
        );
        self::assertSame(
            ["success", "term_conflict"],
            $this->statuses($results)
        );
        self::assertSame(1, $this->eventCount($libraryId));

        $existing = new LibraryGenreId("genre-rename-source");
        $name = new ClassificationTermName("Rename Source");
        $this->genres()->add(new LibraryGenre(
            $libraryId,
            $existing,
            $name,
            ClassificationNameNormalizer::create()->normalize($name),
            ClassificationTermStatus::Active
        ));
        $shared = [
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "name" => "Shared Target",
        ];
        $results = $this->race(
            $shared + [
                "operation" => "genre_rename",
                "term_id" => $existing->value(),
            ],
            $shared + [
                "operation" => "genre_create",
                "term_id" => "genre-create-target",
            ]
        );
        self::assertSame(
            ["success", "term_conflict"],
            $this->statuses($results)
        );
        self::assertSame(2, $this->eventCount($libraryId));
    }

    public function testDeactivateVersusLinkAndLastBookDecisionsAreSerialized(): void
    {
        [$ownerId, $libraryId] = $this->libraryFixture("lifecycle-race");
        $workId = $this->addRepresentedWork($libraryId, "work-link");
        $book = $this->seedBookType($libraryId, "book_type.reading_book");
        $genreId = new LibraryGenreId("genre-link-race");
        $genreName = new ClassificationTermName("Link Race");
        $this->genres()->add(new LibraryGenre(
            $libraryId,
            $genreId,
            $genreName,
            ClassificationNameNormalizer::create()->normalize($genreName),
            ClassificationTermStatus::Active
        ));
        wp_set_current_user($ownerId);
        (new ProductionComposition($this->database))->application()
            ->catalogContextCreation()->createForRepresentedWork(
                $libraryId,
                $workId,
                new LibraryCatalogSelection($book)
            );
        $eventsBefore = $this->eventCount($libraryId);
        $save = array_merge($this->contextPayload(
            $ownerId,
            $libraryId,
            $workId,
            $book
        ), [
            "operation" => "context_save",
            "expected_version" => 1,
            "genre_ids" => [$genreId->value()],
        ]);
        $deactivate = [
            "operation" => "genre_deactivate",
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "term_id" => $genreId->value(),
        ];
        $results = $this->race($save, $deactivate);
        self::assertContains($results[0]["status"], ["success", "validation"]);
        self::assertSame("success", $results[1]["status"]);
        self::assertSame(
            ClassificationTermStatus::Inactive,
            $this->genres()->find($libraryId, $genreId)?->status()
        );
        $stored = $this->contexts()->find($libraryId, $workId);
        self::assertNotNull($stored);

        if ($results[0]["status"] === "success") {
            self::assertSame(
                [$genreId->value()],
                array_map(
                    static fn (LibraryGenreId $id): string => $id->value(),
                    $stored->classification()->genreIds()
                )
            );
            self::assertSame($eventsBefore + 2, $this->eventCount($libraryId));
        } else {
            self::assertSame([], $stored->classification()->genreIds());
            self::assertSame($eventsBefore + 1, $this->eventCount($libraryId));
        }

        $activeBooks = $this->activeBookTypeIds($libraryId);
        foreach (array_slice($activeBooks, 2) as $id) {
            $this->bookTypes()->changeStatus(
                $libraryId,
                new LibraryBookTypeId($id),
                ClassificationTermStatus::Inactive
            );
        }
        $eventsBeforeBooks = $this->eventCount($libraryId);
        $bookBase = [
            "operation" => "book_deactivate",
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "confirm_last_active" => false,
        ];
        $results = $this->race(
            $bookBase + ["term_id" => $activeBooks[0]],
            $bookBase + ["term_id" => $activeBooks[1]]
        );
        self::assertSame(
            ["success", "validation"],
            $this->statuses($results)
        );
        self::assertSame(1, $this->bookTypes()->countActive($libraryId));
        self::assertSame(
            $eventsBeforeBooks + 1,
            $this->eventCount($libraryId)
        );
    }

    /**
     * @param array<string, mixed> $firstPayload
     * @param array<string, mixed> $secondPayload
     * @return list<array<string, mixed>>
     */
    private function race(
        array $firstPayload,
        array $secondPayload,
        ?LibraryId $blockingLibrary = null
    ): array
    {
        $directory = sys_get_temp_dir()
            . "/biblio-classification-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }

        $release = $directory . "/release";
        $workers = [];

        try {
            $workers = [
                $this->startWorker($firstPayload, $directory . "/ready-a", $release),
                $this->startWorker($secondPayload, $directory . "/ready-b", $release),
            ];
            $this->awaitWorkers($workers, $directory);

            if ($blockingLibrary !== null) {
                $this->database->query("START TRANSACTION");
                $libraries = $this->tableNames->libraries();
                $this->database->get_var($this->database->prepare(
                    "SELECT library_id FROM `{$libraries}` "
                    . "WHERE library_id = %s FOR UPDATE",
                    $blockingLibrary->value()
                ));
            }

            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release race barrier.");
            }

            if ($blockingLibrary !== null) {
                usleep(500_000);
                $this->database->query("COMMIT");
            }

            return [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
        } finally {
            if ($blockingLibrary !== null) {
                $this->database->query("ROLLBACK");
            }

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

    /** @param array<string, mixed> $payload */
    private function startWorker(
        array $payload,
        string $ready,
        string $release
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . "/Support/ClassificationManagementWorker.php",
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                $ready,
                $release,
            ],
            [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start classification worker.");
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
                    throw new RuntimeException(
                        "Classification worker exited before barrier: "
                        . stream_get_contents($worker["pipes"][2])
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Classification race timed out.");
            }

            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exitCode = proc_close($worker["process"]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Classification worker failed with {$exitCode}: {$error}"
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

    /** @param list<array<string, mixed>> $results @return list<string> */
    private function statuses(array $results): array
    {
        $statuses = array_column($results, "status");
        sort($statuses);

        return $statuses;
    }

    /** @return array{int, LibraryId} */
    private function libraryFixture(string $name): array
    {
        $ownerId = $this->createWordPressUser($name);
        $libraryId = new LibraryId("library-" . $name);
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
            new UserId((string) $ownerId)
        );

        return [$ownerId, $libraryId];
    }

    private function addRepresentedWork(
        LibraryId $libraryId,
        string $value
    ): WorkId {
        $workId = new WorkId($value);
        $editionId = new EditionId("edition-" . $value);
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add(new Work($workId, "Work {$value}"));
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add(new Edition($editionId, $workId));
        (new WpdbItemRepository(
            $this->database,
            $this->tableNames
        ))->add(Item::active(
            new ItemId("item-" . $value),
            $libraryId,
            $editionId
        ));

        return $workId;
    }

    /** @return array<string, mixed> */
    private function contextPayload(
        int $ownerId,
        LibraryId $libraryId,
        WorkId $workId,
        LibraryBookTypeId $bookType
    ): array {
        return [
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "work_id" => $workId->value(),
            "book_type_id" => $bookType->value(),
            "genre_ids" => [],
            "subject_ids" => [],
        ];
    }

    /** @return array<string, mixed> */
    private function itemAddPayload(
        int $ownerId,
        LibraryId $libraryId,
        WorkId $workId,
        LibraryBookTypeId $bookType,
        string $identity
    ): array {
        return [
            "operation" => "item_add_new_edition",
            "user_id" => $ownerId,
            "library_id" => $libraryId->value(),
            "work_id" => $workId->value(),
            "book_type_id" => $bookType->value(),
            "genre_ids" => [],
            "subject_ids" => [],
            "item_id" => "item-{$identity}",
            "edition_id" => "edition-{$identity}",
        ];
    }

    private function seedBookType(
        LibraryId $libraryId,
        string $key
    ): LibraryBookTypeId {
        $term = $this->bookTypes()->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey($key)
        );
        self::assertNotNull($term);

        return $term->id();
    }

    private function seedGenre(
        LibraryId $libraryId,
        string $key
    ): LibraryGenreId {
        $term = $this->genres()->findBySeedKey(
            $libraryId,
            new ClassificationSeedKey($key)
        );
        self::assertNotNull($term);

        return $term->id();
    }

    /** @return list<string> */
    private function activeBookTypeIds(LibraryId $libraryId): array
    {
        $values = $this->database->get_col($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` "
            . "WHERE library_id = %s AND term_status = 'active' "
            . "ORDER BY book_type_id",
            $libraryId->value()
        ));

        return array_map(
            static fn (mixed $value): string => (string) $value,
            $values
        );
    }

    private function eventCount(LibraryId $libraryId): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE library_id = %s",
            $libraryId->value()
        ));
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }

    private function bookTypes(): WpdbLibraryBookTypeRepository
    {
        return new WpdbLibraryBookTypeRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function genres(): WpdbLibraryGenreRepository
    {
        return new WpdbLibraryGenreRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function contexts(): WpdbLibraryCatalogContextRepository
    {
        return new WpdbLibraryCatalogContextRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
            "display_name" => "Actor {$login}",
        ]);

        if ($result instanceof WP_Error || !is_int($result)) {
            throw new RuntimeException("Could not create test user.");
        }

        return $result;
    }
}
