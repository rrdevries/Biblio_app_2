<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshot;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationIntent;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use DateTimeImmutable;
use RuntimeException;
use WP_Error;

final class BibliographicMaterializationConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testSameExternalEditionCandidateRaceReusesOneWorkAndEdition(): void
    {
        $user = $this->createUser("bibliographic-edition-race");
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Concurrent edition")
        );
        $candidate = $this->editionCandidate(
            $query,
            "volume-same",
            "9780306406157"
        );
        $discovery = new MetadataLookupId("lookup-66666666666666666666666666666666");
        $this->saveSnapshot($discovery, $user, $query, [$candidate]);

        $results = $this->race(
            $user,
            $discovery,
            [$candidate->id(), $candidate->id()],
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame($results[0]["work_id"], $results[1]["work_id"]);
        self::assertSame($results[0]["edition_id"], $results[1]["edition_id"]);
        self::assertSame(1, $this->tableCount($this->tableNames->works()));
        self::assertSame(1, $this->tableCount($this->tableNames->editions()));
        self::assertSame(1, $this->tableCount($this->tableNames->editionIdentifierClaims()));
    }

    public function testDifferentExternalCandidatesForSameIsbnRaceReuseCanonicalEdition(): void
    {
        $user = $this->createUser("bibliographic-isbn-race");
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Concurrent ISBN")
        );
        $first = $this->editionCandidate($query, "volume-a", "9780306406157");
        $second = $this->editionCandidate($query, "volume-b", "9780306406157");
        $discovery = new MetadataLookupId("lookup-77777777777777777777777777777777");
        $this->saveSnapshot($discovery, $user, $query, [$first, $second]);

        $results = $this->race(
            $user,
            $discovery,
            [$first->id(), $second->id()],
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame($results[0]["work_id"], $results[1]["work_id"]);
        self::assertSame($results[0]["edition_id"], $results[1]["edition_id"]);
        self::assertSame(1, $this->tableCount($this->tableNames->works()));
        self::assertSame(1, $this->tableCount($this->tableNames->editions()));
        self::assertSame(1, $this->tableCount($this->tableNames->editionIdentifierClaims()));
        self::assertSame(4, $this->tableCount($this->tableNames->bibliographicProviderIdentities()));
    }

    public function testSameExternalWorkCandidateRaceReusesOneProvisionalWork(): void
    {
        $user = $this->createUser("bibliographic-work-race");
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Concurrent Work")
        );
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "open_library",
            "/works/OL999W",
            "/works/OL999W",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Concurrent Work",
            null,
            null,
            ["Concurrent Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $discovery = new MetadataLookupId("lookup-88888888888888888888888888888888");
        $this->saveSnapshot($discovery, $user, $query, [$candidate]);

        $results = $this->race(
            $user,
            $discovery,
            [$candidate->id(), $candidate->id()],
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame($results[0]["work_id"], $results[1]["work_id"]);
        self::assertNull($results[0]["edition_id"]);
        self::assertNull($results[1]["edition_id"]);
        self::assertSame(1, $this->tableCount($this->tableNames->works()));
        self::assertSame(0, $this->tableCount($this->tableNames->editions()));
        self::assertSame(0, $this->tableCount($this->tableNames->items()));
    }

    private function editionCandidate(
        BibliographicDiscoveryQuery $query,
        string $recordId,
        string $isbn
    ): BibliographicDiscoveryCandidate {
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "google_books",
            $recordId,
            null,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Concurrent Edition",
            CanonicalIsbnIdentity::fromIsbn(new Isbn13($isbn)),
            null,
            ["Concurrent Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
    }

    /** @param list<BibliographicDiscoveryCandidate> $candidates */
    private function saveSnapshot(
        MetadataLookupId $discovery,
        int $user,
        BibliographicDiscoveryQuery $query,
        array $candidates
    ): void {
        (new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new BibliographicDiscoverySnapshot(
            $discovery,
            new UserId((string) $user),
            $query,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            new DateTimeImmutable("2099-09-10T12:30:00+00:00"),
            $candidates
        ));
    }

    /**
     * @param list<string> $candidateIds
     * @return list<array{work_id:string,edition_id:?string,reused:bool}>
     */
    private function race(
        int $user,
        MetadataLookupId $discovery,
        array $candidateIds,
        BibliographicMaterializationIntent $intent
    ): array {
        $directory = sys_get_temp_dir() . "/biblio-bibliographic-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create bibliographic race directory.");
        }
        $workers = [];
        try {
            foreach ($candidateIds as $candidateId) {
                $workers[] = $this->startWorker(
                    $user,
                    $discovery,
                    $candidateId,
                    $intent,
                    $directory
                );
            }
            $this->awaitWorkers($workers, $directory);
            if (file_put_contents($directory . "/release", "release") === false) {
                throw new RuntimeException("Could not release bibliographic race barrier.");
            }
            return array_map($this->finishWorker(...), $workers);
        } finally {
            foreach ($workers as $worker) { $this->terminateWorker($worker); }
            foreach (glob($directory . "/*") ?: [] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startWorker(
        int $user,
        MetadataLookupId $discovery,
        string $candidateId,
        BibliographicMaterializationIntent $intent,
        string $directory
    ): array {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . "/Support/BibliographicMaterializationWorker.php",
            (string) $user,
            $discovery->value(),
            $candidateId,
            $intent->value,
            $directory,
        ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start bibliographic race worker.");
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
                        "Bibliographic worker exited before barrier: "
                        . stream_get_contents($worker["pipes"][2])
                    );
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Bibliographic race barrier timed out.");
            }
            usleep(10_000);
        }
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>} $worker
     * @return array{work_id:string,edition_id:?string,reused:bool}
     */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker["pipes"][1]);
        $stderr = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Bibliographic worker failed: {$stderr}");
        }
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException("Bibliographic worker returned invalid JSON.");
        }
        return $result;
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function terminateWorker(array $worker): void
    {
        if (!is_resource($worker["process"])) { return; }
        $status = proc_get_status($worker["process"]);
        if ($status["running"]) { proc_terminate($worker["process"]); }
    }

    private function createUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
        ]);
        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);
        wp_set_current_user($result);
        (new ProductionComposition($this->database))->application()
            ->personalLibraries()
            ->ensure();
        wp_set_current_user(0);
        return $result;
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
