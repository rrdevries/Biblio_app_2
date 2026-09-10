<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{Edition,EditionId,Work,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbEditionRepository,WpdbWorkRepository};
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchemaMigrationRegistry,CoreSchemaMigrator};
use RuntimeException;

final class WishlistConcurrencyTest extends PersistenceIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        ))->migrate();
    }

    public function testConcurrentDuplicateAddIsIdempotent(): void
    {
        $this->seedCatalog();
        $results = $this->race([
            ["add_work", "-"],
            ["add_work", "-"],
        ]);

        self::assertSame(["created", "reused"], $this->statuses($results));
        self::assertSame($results[0]["entry_id"], $results[1]["entry_id"]);
        self::assertSame(1, $this->entryCount());
    }

    public function testConcurrentMixedIntentCannotCreateMixedState(): void
    {
        $this->seedCatalog();
        $results = $this->race([
            ["add_work", "-"],
            ["add_edition", "edition-race"],
        ]);

        self::assertSame(["conflict", "created"], $this->statuses($results));
        self::assertSame(1, $this->entryCount());
        self::assertContains((string) $this->database->get_var(
            "SELECT target_type FROM `{$this->tableNames->wishlistWorkStates()}`"
        ), ["work_only", "edition_specific"]);
    }

    public function testConcurrentRefinementPreservesOneStableEntry(): void
    {
        $this->seedCatalog();
        $created = $this->race([
            ["add_work", "-"],
            ["add_work", "-"],
        ]);
        $originalId = $created[0]["entry_id"];

        $refined = $this->race([
            ["refine", "edition-race"],
            ["refine", "edition-race"],
        ]);

        self::assertSame(["refined", "reused"], $this->statuses($refined));
        self::assertSame([$originalId, $originalId], array_column($refined, "entry_id"));
        self::assertSame(1, $this->entryCount());
        self::assertSame("edition-race", (string) $this->database->get_var(
            "SELECT edition_id FROM `{$this->tableNames->wishlistEntries()}`"
        ));
    }

    /** @param list<array{0:string,1:string}> $operations */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir() . "/biblio-wishlist-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Wishlist race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($operations as $index => [$action, $edition]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/WishlistMutationWorker.php",
                    $action,
                    "wishlist-race-user",
                    "wishlist-race-work",
                    $edition,
                    $ready[$index],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Wishlist worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Wishlist worker exited early: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Wishlist workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release Wishlist workers.");
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
            throw new RuntimeException("Wishlist worker failed: {$error}");
        }
        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,string>> $results @return list<string> */
    private function statuses(array $results): array
    {
        $statuses = array_column($results, "status");
        sort($statuses);
        return $statuses;
    }

    private function seedCatalog(): void
    {
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $works->add(new Work(new WorkId("wishlist-race-work"), "Race Work"));
        $editions->add(new Edition(
            new EditionId("edition-race"),
            new WorkId("wishlist-race-work"),
            "Race Edition"
        ));
    }

    private function entryCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}`"
        );
    }
}
