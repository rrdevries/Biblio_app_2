<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{Author,AuthorId,AuthorIdentityStatus,Work,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbWorkRepository
};
use RuntimeException;

final class CanonicalAuthorMaterializationConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testConcurrentSameProviderAuthorConvergesToOneAuthorAndClaim(): void
    {
        $this->seedWork("work-one");
        $results = $this->runWorkers([
            ["provider", "work-one", "OL111A", "/works/OL101W", "Peter King", "a", 1],
            ["provider", "work-one", "OL111A", "/works/OL101W", "Peter King", "b", 1],
        ]);

        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->bibliographicProviderIdentities()));
        self::assertSame(1, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(1, array_sum(array_column($results, "races")));
        self::assertCount(1, array_unique(array_column($results, "author_id")));
    }

    public function testConcurrentSameCreditConvergesToOneCreditAndEdge(): void
    {
        $this->seedWork("work-one");
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $authors->add(new Author(
            new AuthorId("existing-author"),
            "Peter King",
            AuthorIdentityStatus::Resolved
        ));
        (new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        ))->claimAuthor("open_library", "/authors/OL111A", new AuthorId("existing-author"));

        $results = $this->runWorkers([
            ["credit", "work-one", "OL111A", "/works/OL101W", "Peter King", "a", 1],
            ["credit", "work-one", "OL111A", "/works/OL101W", "Peter King", "b", 1],
        ]);

        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(1, array_sum(array_column($results, "races")));
        self::assertSame(["existing-author"], array_values(array_unique(
            array_column($results, "author_id")
        )));
    }

    public function testConcurrentSameProviderAuthorOnTwoWorksCreatesTwoCreditsAndEdges(): void
    {
        $this->seedWork("work-one");
        $this->seedWork("work-two");
        $results = $this->runWorkers([
            ["provider", "work-one", "OL111A", "/works/OL101W", "Peter King", "a", 1],
            ["provider", "work-two", "OL111A", "/works/OL202W", "P. King", "b", 1],
        ]);

        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->bibliographicProviderIdentities()));
        self::assertSame(2, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->countRows($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(1, array_sum(array_column($results, "races")));
        self::assertCount(1, array_unique(array_column($results, "author_id")));
    }

    /**
     * @param list<array{string,string,string,string,string,string,int}> $commands
     * @return list<array{status:string,author_id:?string,races:int}>
     */
    private function runWorkers(array $commands): array
    {
        $directory = sys_get_temp_dir() . "/biblio-author-materialization-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Author materialization barrier.");
        }
        $workers = [];
        try {
            foreach ($commands as [$mode, $work, $author, $source, $name, $token, $position]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/CanonicalAuthorMaterializationWorker.php",
                    $mode,
                    $work,
                    $author,
                    $source,
                    $name,
                    $token,
                    $directory,
                    (string) $position,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Author materialization worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }
            $deadline = microtime(true) + 15;
            while (count(glob($directory . "/ready-*") ?: []) < count($workers)) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Author materialization worker exited before barrier: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Author materialization barrier timed out.");
                }
                usleep(10_000);
            }
            if (file_put_contents($directory . "/release", "release") === false) {
                throw new RuntimeException("Could not release Author materialization workers.");
            }

            return array_map($this->finishWorker(...), $workers);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker["process"]) && proc_get_status($worker["process"])["running"]) {
                    proc_terminate($worker["process"]);
                }
            }
            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker["pipes"][1]);
        $stderr = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Author materialization worker failed: {$stderr}");
        }
        $result = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($result)
            || !is_string($result["status"] ?? null)
            || !(is_string($result["author_id"] ?? null) || ($result["author_id"] ?? null) === null)
            || !is_int($result["races"] ?? null)) {
            throw new RuntimeException("Author materialization worker returned invalid JSON.");
        }
        return $result;
    }

    private function seedWork(string $id): void
    {
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work(new WorkId($id), "Fixture {$id}")
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
