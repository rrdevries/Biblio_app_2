<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Author\{
    AuthorCreditProviderSourceType,
    OpenLibraryAuthorId
};
use Biblio\Core\Application\Metadata\Discovery\BibliographicAuthorCredit;
use Biblio\Core\Application\Metadata\{
    MetadataCandidate,
    MetadataCandidateId,
    MetadataLookupId,
    MetadataLookupSnapshot,
    MetadataMatchMethod
};
use Biblio\Core\Catalog\{
    CanonicalIsbnIdentity,
    ContributorPosition,
    ContributorRole,
    Isbn13
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataLookupSnapshotRepository;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use WP_Error;

final class AddBookAuthorMaterializationConcurrencyTest extends PersistenceIntegrationTestCase
{
    private int $wordpressUserId;

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        if (isset($this->wordpressUserId)) {
            $this->database->delete(
                $this->database->usermeta,
                ["user_id" => $this->wordpressUserId]
            );
            $this->database->delete(
                $this->database->users,
                ["ID" => $this->wordpressUserId]
            );
        }
        parent::tearDown();
    }

    public function testConcurrentAddBookOperationsShareStrongAuthorWithoutOrphans(): void
    {
        $this->wordpressUserId = $this->createUser();
        wp_set_current_user($this->wordpressUserId);
        $libraryId = (new ProductionComposition($this->database))
            ->application()->personalLibraries()->ensure();
        $bookTypeId = $this->database->get_var($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` "
                . "WHERE library_id=%s AND term_status='active' "
                . "ORDER BY display_name,book_type_id LIMIT 1",
            $libraryId->value()
        ));
        self::assertIsString($bookTypeId);

        $first = $this->candidate(
            "9780306406157",
            "/books/OL801M",
            "Concurrent Add Book One"
        );
        $second = $this->candidate(
            "9780441172719",
            "/books/OL802M",
            "Concurrent Add Book Two"
        );
        $firstLookup = new MetadataLookupId(
            "lookup-80808080808080808080808080808080"
        );
        $secondLookup = new MetadataLookupId(
            "lookup-90909090909090909090909090909090"
        );
        $this->saveSnapshot($firstLookup, $libraryId, $first);
        $this->saveSnapshot($secondLookup, $libraryId, $second);
        wp_set_current_user(0);

        $results = $this->race([
            [$firstLookup, $first],
            [$secondLookup, $second],
        ], $libraryId, $bookTypeId);

        self::assertNotSame($results[0]["work_id"], $results[1]["work_id"]);
        self::assertNotSame($results[0]["edition_id"], $results[1]["edition_id"]);
        self::assertNotSame($results[0]["item_id"], $results[1]["item_id"]);
        self::assertSame(2, $this->tableCount($this->tableNames->works()));
        self::assertSame(2, $this->tableCount($this->tableNames->editions()));
        self::assertSame(2, $this->tableCount($this->tableNames->items()));
        self::assertSame(1, $this->tableCount($this->tableNames->authors()));
        self::assertSame(2, $this->tableCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->tableCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, $this->tableCount($this->tableNames->workContributors()));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE source_entity_type='author' AND target_type='author'"
        ));
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $user = wp_insert_user([
            "user_login" => "add-book-author-race-{$suffix}",
            "user_pass" => "integration-test-only",
            "user_email" => "add-book-author-race-{$suffix}@example.invalid",
        ]);
        if ($user instanceof WP_Error) {
            throw new RuntimeException($user->get_error_message());
        }
        return $user;
    }

    private function candidate(
        string $isbn,
        string $recordId,
        string $title
    ): MetadataCandidate {
        $identity = CanonicalIsbnIdentity::fromIsbn(new Isbn13($isbn));
        $credit = new BibliographicAuthorCredit(
            "Concurrent Shared Author",
            ContributorRole::Author,
            new ContributorPosition(1),
            AuthorCreditProviderSourceType::Edition,
            $recordId,
            new OpenLibraryAuthorId("OL800A")
        );
        return new MetadataCandidate(
            "open_library",
            $recordId,
            new DateTimeImmutable("2026-09-14T09:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            $title,
            null,
            [$credit->observedDisplayName()],
            [],
            [],
            null,
            null,
            null,
            null,
            [$credit]
        );
    }

    private function saveSnapshot(
        MetadataLookupId $lookup,
        LibraryId $libraryId,
        MetadataCandidate $candidate
    ): void {
        (new WpdbMetadataLookupSnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new MetadataLookupSnapshot(
            $lookup,
            new UserId((string) $this->wordpressUserId),
            $libraryId,
            $candidate->queriedIsbn(),
            new DateTimeImmutable("2026-09-14T09:00:00+00:00"),
            new DateTimeImmutable("2099-09-14T09:30:00+00:00"),
            [$candidate]
        ));
    }

    /**
     * @param list<array{MetadataLookupId,MetadataCandidate}> $operations
     * @return list<array{work_id:string,edition_id:string,item_id:string}>
     */
    private function race(
        array $operations,
        LibraryId $libraryId,
        string $bookTypeId
    ): array {
        $directory = sys_get_temp_dir() . "/biblio-add-book-author-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Add Book race directory.");
        }
        $workers = [];
        try {
            foreach ($operations as [$lookup, $candidate]) {
                $workers[] = $this->startWorker(
                    $lookup,
                    $candidate,
                    $libraryId,
                    $bookTypeId,
                    $directory
                );
            }
            $this->awaitWorkers($workers, $directory);
            if (file_put_contents($directory . "/release", "release") === false) {
                throw new RuntimeException("Could not release Add Book race barrier.");
            }
            return array_map($this->finishWorker(...), $workers);
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
            }
            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startWorker(
        MetadataLookupId $lookup,
        MetadataCandidate $candidate,
        LibraryId $libraryId,
        string $bookTypeId,
        string $directory
    ): array {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . "/Support/AddBookAuthorMaterializationWorker.php",
            (string) $this->wordpressUserId,
            $libraryId->value(),
            $bookTypeId,
            $candidate->queriedIsbn()->isbn13()->value(),
            $lookup->value(),
            MetadataCandidateId::fromCandidate($candidate)->value(),
            $directory,
        ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start Add Book race worker.");
        }
        return ["process" => $process, "pipes" => $pipes];
    }

    /** @param list<array{process:resource,pipes:array<int,resource>}> $workers */
    private function awaitWorkers(array $workers, string $directory): void
    {
        $deadline = microtime(true) + 15;
        while (count(glob($directory . "/ready-*") ?: []) < count($workers)) {
            foreach ($workers as $worker) {
                if (!proc_get_status($worker["process"])["running"]) {
                    throw new RuntimeException(
                        "Add Book worker exited before barrier: "
                            . stream_get_contents($worker["pipes"][2])
                    );
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Add Book race barrier timed out.");
            }
            usleep(10_000);
        }
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>} $worker
     * @return array{work_id:string,edition_id:string,item_id:string}
     * @throws JsonException
     */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker["pipes"][1]);
        $stderr = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Add Book worker failed: {$stderr}");
        }
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException("Add Book worker returned invalid JSON.");
        }
        return $result;
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function terminateWorker(array $worker): void
    {
        if (!is_resource($worker["process"])) {
            return;
        }
        if (proc_get_status($worker["process"])["running"]) {
            proc_terminate($worker["process"]);
        }
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
