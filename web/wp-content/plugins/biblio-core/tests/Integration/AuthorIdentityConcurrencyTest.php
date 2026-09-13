<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditSourceIdentity,
    AuthorContributorCreditStatus,
    AuthorContributorCreditVersion,
    AuthorProviderIdentityConflict
};
use Biblio\Core\Catalog\{
    Author,
    AuthorId,
    ContributorPosition,
    ContributorRole,
    Work,
    WorkId
};
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorContributorCreditRepository,
    WpdbAuthorRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbWorkRepository
};
use DateTimeImmutable;
use RuntimeException;

final class AuthorIdentityConcurrencyTest extends PersistenceIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work(new WorkId("concurrency-work"), "Concurrency Work")
        );
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $authors->add(new Author(
            new AuthorId("concurrency-author-a"),
            "Peter King"
        ));
        $authors->add(new Author(
            new AuthorId("concurrency-author-b"),
            "Peter King"
        ));
    }

    public function testConcurrentSameProviderAuthorProducesOneClaimAndRetryReuse(): void
    {
        $outcomes = $this->runWorkers([
            ["provider", "/authors/OL-RACE-A", "concurrency-author-a", ""],
            ["provider", "/authors/OL-RACE-A", "concurrency-author-a", ""],
        ]);

        sort($outcomes);
        self::assertSame(["race", "success"], $outcomes);
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $repository->claimAuthor(
            "open_library",
            "/authors/OL-RACE-A",
            new AuthorId("concurrency-author-a")
        );
        self::assertSame(1, $this->claimCount("/authors/OL-RACE-A"));
    }

    public function testConcurrentConflictingProviderTargetsFailClosedAfterRetry(): void
    {
        $outcomes = $this->runWorkers([
            ["provider", "/authors/OL-RACE-B", "concurrency-author-a", ""],
            ["provider", "/authors/OL-RACE-B", "concurrency-author-b", ""],
        ]);

        sort($outcomes);
        self::assertSame(["race", "success"], $outcomes);
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $winner = $repository->findAuthor(
            "open_library",
            "/authors/OL-RACE-B"
        )?->value();
        $loser = $winner === "concurrency-author-a"
            ? "concurrency-author-b" : "concurrency-author-a";
        try {
            $repository->claimAuthor(
                "open_library",
                "/authors/OL-RACE-B",
                new AuthorId($loser)
            );
            self::fail("Conflicting provider Author retry was accepted.");
        } catch (AuthorProviderIdentityConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, $this->claimCount("/authors/OL-RACE-B"));
    }

    public function testConcurrentSameCreditConvergesAndIndependentSameNamesRemainSeparate(): void
    {
        $same = $this->runWorkers([
            ["credit", "/works/SAME-CREDIT", "credit-race-a", "concurrency-author-a"],
            ["credit", "/works/SAME-CREDIT", "credit-race-b", "concurrency-author-a"],
        ]);
        sort($same);
        self::assertSame(["race", "success"], $same);
        self::assertSame(1, $this->creditCount());

        $repository = new WpdbAuthorContributorCreditRepository(
            $this->database,
            $this->tableNames
        );
        $retry = $repository->create($this->credit(
            "credit-retry",
            "/works/SAME-CREDIT"
        ));
        self::assertContains(
            $retry->id()->value(),
            ["credit-race-a", "credit-race-b"]
        );

        $independent = $this->runWorkers([
            ["credit", "/works/INDEPENDENT-A", "credit-independent-a", "concurrency-author-a"],
            ["credit", "/works/INDEPENDENT-B", "credit-independent-b", "concurrency-author-b"],
        ]);
        self::assertSame(["success", "success"], $independent);
        self::assertSame(3, $this->creditCount());
        self::assertSame(
            "concurrency-author-a",
            $repository->findByKey(
                $this->credit("ignored-a", "/works/INDEPENDENT-A")->key()
            )?->authorId()?->value()
        );
        self::assertSame(
            "concurrency-author-b",
            $repository->findByKey(
                $this->credit("ignored-b", "/works/INDEPENDENT-B")->key()
            )?->authorId()?->value()
        );
    }

    /**
     * @param list<array{string,string,string,string}> $commands
     * @return list<string>
     */
    private function runWorkers(array $commands): array
    {
        $directory = sys_get_temp_dir() . "/biblio-author-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Author race directory.");
        }
        $workers = [];
        try {
            foreach (
                $commands
                as $offset => [$mode, $record, $target, $creditAuthor]
            ) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/AuthorIdentityWorker.php",
                    $mode,
                    $record,
                    $target,
                    $creditAuthor,
                    (string) $offset,
                    $directory,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Author race worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }
            $deadline = microtime(true) + 15;
            while (count(glob($directory . "/ready-*") ?: []) < count($workers)) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Author worker exited before barrier: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Author race barrier timed out.");
                }
                usleep(10_000);
            }
            if (file_put_contents($directory . "/release", "release") === false) {
                throw new RuntimeException("Could not release Author race barrier.");
            }

            return array_map($this->finishWorker(...), $workers);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker["process"])) {
                    $status = proc_get_status($worker["process"]);
                    if ($status["running"]) {
                        proc_terminate($worker["process"]);
                    }
                }
            }
            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>} $worker
     */
    private function finishWorker(array $worker): string
    {
        $stdout = stream_get_contents($worker["pipes"][1]);
        $stderr = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Author identity worker failed: {$stderr}");
        }
        $result = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_string($result["status"] ?? null)) {
            throw new RuntimeException("Author identity worker returned invalid JSON.");
        }
        return $result["status"];
    }

    private function credit(string $id, string $record): AuthorContributorCredit
    {
        $source = AuthorContributorCreditSourceIdentity::provider(
            "open_library",
            "work",
            $record,
            1
        );
        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        return new AuthorContributorCredit(
            new AuthorContributorCreditId($id),
            AuthorContributorCreditKey::fromSource(
                new WorkId("concurrency-work"),
                ContributorRole::Author,
                new ContributorPosition(1),
                "Peter King",
                $source
            ),
            new WorkId("concurrency-work"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Peter King",
            new AuthorId("concurrency-author-a"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        );
    }

    private function claimCount(string $record): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE provider_key='open_library' "
                . "AND source_entity_type='author' AND provider_record_id=%s",
            $record
        ));
    }

    private function creditCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}`"
        );
    }
}
