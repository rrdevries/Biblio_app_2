<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\CandidateClassifier;
use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryService;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshot;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshotRepository;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryStatus;
use Biblio\Core\Application\Metadata\Discovery\BibliographicLocalDiscoveryRepository;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderDiscoveryResult;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextDiscoveryProvider;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupService;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupIdGenerator;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\BibliographicMetadataRepository;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BibliographicDiscoveryServiceTest extends TestCase
{
    public function testTextDiscoveryKeepsExternalBreadthAfterOneWorkIsMaterialized(): void
    {
        $text = new BibliographicTextQuery("harry potter");
        $query = BibliographicDiscoveryQuery::text($text);
        $firstExternal = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "primary",
            "provider-work-1",
            "provider-work-1",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Harry Potter and the Philosopher's Stone",
            null,
            null,
            ["J. K. Rowling"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $secondExternal = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "primary",
            "provider-work-2",
            "provider-work-2",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Harry Potter and the Chamber of Secrets",
            null,
            null,
            ["J. K. Rowling"],
            [],
            [],
            null,
            null,
            null,
            1
        );
        $local = new DiscoveryLocalRepository([]);
        $primary = new DiscoveryTextProvider(
            "primary",
            BibliographicProviderDiscoveryResult::candidates([
                $firstExternal,
                $secondExternal,
            ])
        );
        $fallback = new DiscoveryTextProvider(
            "fallback", BibliographicProviderDiscoveryResult::miss()
        );
        $identities = new DiscoveryProviderIdentityRepository();
        $snapshots = new DiscoverySnapshotRepository();
        $service = $this->service(
            $local, $primary, $fallback, $snapshots, null, null, $identities
        );

        $beforeMaterialization = $service->discover($text->value());

        self::assertSame([
            "provider-work-1",
            "provider-work-2",
        ], array_map(
            static fn (BibliographicDiscoveryCandidate $candidate): ?string =>
                $candidate->providerRecordId(),
            $beforeMaterialization->candidates()
        ));

        $materializedWorkId = new WorkId("materialized-work");
        $identities->claimWork(
            "primary", "work", "provider-work-1", $materializedWorkId
        );
        $local->replaceResults([
            BibliographicDiscoveryCandidate::localWork(
                $materializedWorkId,
                "Harry Potter and the Philosopher's Stone",
                $query,
                0,
                ["J. K. Rowling"]
            ),
        ]);

        $afterMaterialization = $service->discover($text->value());

        self::assertSame(BibliographicDiscoveryStatus::Results, $afterMaterialization->status());
        self::assertSame([
            BibliographicCandidateType::LocalWork,
            BibliographicCandidateType::ExternalWork,
        ], array_map(
            static fn (BibliographicDiscoveryCandidate $candidate): BibliographicCandidateType =>
                $candidate->type(),
            $afterMaterialization->candidates()
        ));
        self::assertSame(
            "provider-work-2",
            $afterMaterialization->candidates()[1]->providerRecordId()
        );
        self::assertNotNull($afterMaterialization->discoveryId());
        self::assertSame(2, $primary->calls());
        self::assertSame(0, $fallback->calls());
        self::assertCount(1, $snapshots->saved()?->candidates() ?? []);
        self::assertSame(
            "provider-work-2",
            array_values($snapshots->saved()?->candidates() ?? [])[0]->providerRecordId()
        );
    }

    public function testTextDiscoveryKeepsLocalResultsWhenExternalExpansionFails(): void
    {
        $query = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("Local title"));
        $local = new DiscoveryLocalRepository([
            BibliographicDiscoveryCandidate::localWork(
                new WorkId("local-work"), "Local title", $query, 0
            ),
        ]);
        $primary = new DiscoveryTextProvider(
            "primary",
            BibliographicProviderDiscoveryResult::failure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Network
            )
        );
        $fallback = new DiscoveryTextProvider(
            "fallback", BibliographicProviderDiscoveryResult::miss()
        );
        $snapshots = new DiscoverySnapshotRepository();

        $result = $this->service($local, $primary, $fallback, $snapshots)
            ->discover("  Local   title  ");

        self::assertSame(BibliographicDiscoveryStatus::Results, $result->status());
        self::assertSame(BibliographicCandidateType::LocalWork, $result->candidates()[0]->type());
        self::assertNull($result->discoveryId());
        self::assertSame(1, $primary->calls());
        self::assertSame(1, $fallback->calls());
        self::assertSame(ProviderLookupStatus::Unavailable->value, $result->attempts()[0]["status"]);
        self::assertNull($snapshots->saved());
    }

    public function testTextDiscoveryNeverDeduplicatesByMatchingTitleAlone(): void
    {
        $text = new BibliographicTextQuery("Same title");
        $query = BibliographicDiscoveryQuery::text($text);
        $local = BibliographicDiscoveryCandidate::localWork(
            new WorkId("local-work"), "Same title", $query, 0
        );
        $external = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "primary",
            "unmapped-provider-work",
            "unmapped-provider-work",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Same title",
            null,
            null,
            [],
            [],
            [],
            null,
            null,
            null,
            0
        );

        $result = $this->service(
            new DiscoveryLocalRepository([$local]),
            new DiscoveryTextProvider(
                "primary", BibliographicProviderDiscoveryResult::candidates([$external])
            ),
            new DiscoveryTextProvider(
                "fallback", BibliographicProviderDiscoveryResult::miss()
            ),
            new DiscoverySnapshotRepository()
        )->discover($text->value());

        self::assertCount(2, $result->candidates());
        self::assertSame(BibliographicCandidateType::LocalWork, $result->candidates()[0]->type());
        self::assertSame(BibliographicCandidateType::ExternalWork, $result->candidates()[1]->type());
    }

    public function testExternalResultsAreSnapshottedAndFallbackIsNotCalled(): void
    {
        $text = new BibliographicTextQuery("External title author");
        $query = BibliographicDiscoveryQuery::text($text);
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "primary",
            "provider-work-1",
            "provider-work-1",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "External title",
            null,
            null,
            ["External Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $primary = new DiscoveryTextProvider(
            "primary", BibliographicProviderDiscoveryResult::candidates([$candidate])
        );
        $fallback = new DiscoveryTextProvider(
            "fallback", BibliographicProviderDiscoveryResult::miss()
        );
        $snapshots = new DiscoverySnapshotRepository();

        $result = $this->service(
            new DiscoveryLocalRepository([]),
            $primary,
            $fallback,
            $snapshots
        )->discover($text->value());

        self::assertSame(BibliographicDiscoveryStatus::Results, $result->status());
        self::assertSame("lookup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", $result->discoveryId()?->value());
        self::assertSame(1, $primary->calls());
        self::assertSame(0, $fallback->calls());
        self::assertSame("actor-1", $snapshots->saved()?->actorId()->value());
        self::assertSame("2026-09-10T12:30:00+00:00", $snapshots->saved()?->expiresAt()->format(DATE_ATOM));
    }

    public function testMalformedProviderResponseIsNotCollapsedIntoNoResults(): void
    {
        $primary = new DiscoveryTextProvider(
            "primary", BibliographicProviderDiscoveryResult::miss()
        );
        $fallback = new DiscoveryTextProvider(
            "fallback",
            BibliographicProviderDiscoveryResult::failure(
                ProviderLookupStatus::InvalidResponse,
                ProviderFailureReason::Malformed
            )
        );

        $result = $this->service(
            new DiscoveryLocalRepository([]),
            $primary,
            $fallback,
            new DiscoverySnapshotRepository()
        )->discover("missing title");

        self::assertSame(
            BibliographicDiscoveryStatus::InvalidProviderResponse,
            $result->status()
        );
        self::assertSame([], $result->candidates());
        self::assertSame(1, $fallback->calls());
    }

    public function testPrimaryFailureFallsBackToProviderResults(): void
    {
        $text = new BibliographicTextQuery("Fallback title author");
        $query = BibliographicDiscoveryQuery::text($text);
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "fallback",
            "fallback-volume",
            null,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Fallback title",
            null,
            null,
            ["Fallback Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $primary = new DiscoveryTextProvider(
            "primary",
            BibliographicProviderDiscoveryResult::failure(
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Network
            )
        );
        $fallback = new DiscoveryTextProvider(
            "fallback",
            BibliographicProviderDiscoveryResult::candidates([$candidate])
        );

        $result = $this->service(
            new DiscoveryLocalRepository([]),
            $primary,
            $fallback,
            new DiscoverySnapshotRepository()
        )->discover($text->value());

        self::assertSame(BibliographicDiscoveryStatus::Results, $result->status());
        self::assertSame("fallback-volume", $result->candidates()[0]->providerRecordId());
        self::assertSame(1, $primary->calls());
        self::assertSame(1, $fallback->calls());
    }

    public function testDoubleMissAndDoubleConfigurationFailureRemainDistinct(): void
    {
        $miss = $this->service(
            new DiscoveryLocalRepository([]),
            new DiscoveryTextProvider(
                "primary",
                BibliographicProviderDiscoveryResult::miss()
            ),
            new DiscoveryTextProvider(
                "fallback",
                BibliographicProviderDiscoveryResult::miss()
            ),
            new DiscoverySnapshotRepository()
        )->discover("ordinary miss");
        $configuration = BibliographicProviderDiscoveryResult::failure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
        $configurationFailure = $this->service(
            new DiscoveryLocalRepository([]),
            new DiscoveryTextProvider("primary", $configuration),
            new DiscoveryTextProvider("fallback", $configuration),
            new DiscoverySnapshotRepository()
        )->discover("configuration failure");

        self::assertSame(BibliographicDiscoveryStatus::NoResults, $miss->status());
        self::assertSame(
            BibliographicDiscoveryStatus::ConfigurationFailure,
            $configurationFailure->status()
        );
    }

    public function testValidIsbnUsesCanonicalLocalEditionPathNotTextProviders(): void
    {
        $work = new Work(new WorkId("isbn-work"), "ISBN Work");
        $edition = new Edition(
            new EditionId("isbn-edition"),
            $work->id(),
            "ISBN Edition",
            EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
        );
        $primary = new DiscoveryTextProvider(
            "primary", BibliographicProviderDiscoveryResult::miss()
        );
        $fallback = new DiscoveryTextProvider(
            "fallback", BibliographicProviderDiscoveryResult::miss()
        );

        $result = $this->service(
            new DiscoveryLocalRepository([]),
            $primary,
            $fallback,
            new DiscoverySnapshotRepository(),
            $edition,
            $work
        )->discover("0-306-40615-2");

        self::assertSame("isbn", $result->query()->type()->value);
        self::assertSame("9780306406157", $result->query()->normalizedValue());
        self::assertSame(BibliographicCandidateType::LocalEdition, $result->candidates()[0]->type());
        self::assertSame(0, $primary->calls());
        self::assertSame(0, $fallback->calls());
    }

    private function service(
        BibliographicLocalDiscoveryRepository $local,
        BibliographicTextDiscoveryProvider $primary,
        BibliographicTextDiscoveryProvider $fallback,
        BibliographicDiscoverySnapshotRepository $snapshots,
        ?Edition $localEdition = null,
        ?Work $localWork = null,
        ?BibliographicProviderIdentityRepository $providerIdentities = null
    ): BibliographicDiscoveryService {
        $claims = $this->createStub(EditionIdentifierClaimRepository::class);
        $claims->method("findByCanonicalIsbn13")
            ->willReturn($localEdition?->id());
        $editions = $this->createStub(EditionRepository::class);
        $editions->method("find")->willReturn($localEdition);
        $metadata = $this->createStub(BibliographicMetadataRepository::class);
        $metadata->method("editionsForIsbns")->willReturn([]);
        $works = $this->createStub(WorkRepository::class);
        $works->method("find")->willReturn($localWork);

        return new BibliographicDiscoveryService(
            new DiscoveryActor(),
            new IsbnCanonicalizer(),
            $local,
            new LocalEditionResolver(
                new IsbnCanonicalizer(), $claims, $editions, $metadata
            ),
            $works,
            new FirstSufficientMetadataLookupService(
                new CandidateClassifier(),
                new DiscoveryIsbnProvider("isbn-primary"),
                new DiscoveryIsbnProvider("isbn-fallback")
            ),
            $primary,
            $fallback,
            $providerIdentities ?? new DiscoveryProviderIdentityRepository(),
            $snapshots,
            new DiscoveryLookupIds(),
            new DiscoveryClock(),
            new DiscoveryTransactionManager()
        );
    }
}

final readonly class DiscoveryActor implements AuthenticatedUser
{
    public function requireUserId(): UserId { return new UserId("actor-1"); }
}

final class DiscoveryLocalRepository implements BibliographicLocalDiscoveryRepository
{
    /** @param list<BibliographicDiscoveryCandidate> $results */
    public function __construct(private array $results) {}
    public function searchText(BibliographicDiscoveryQuery $query, int $limit = 20): array
    {
        return $this->results;
    }
    /** @param list<BibliographicDiscoveryCandidate> $results */
    public function replaceResults(array $results): void { $this->results = $results; }
}

final class DiscoveryProviderIdentityRepository implements BibliographicProviderIdentityRepository
{
    /** @var array<string,WorkId> */
    private array $works = [];
    /** @var array<string,EditionId> */
    private array $editions = [];

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        return $this->works["{$provider}:{$sourceType}:{$recordId}"] ?? null;
    }

    public function findEdition(string $provider, string $recordId): ?EditionId
    {
        return $this->editions["{$provider}:{$recordId}"] ?? null;
    }

    public function claimWork(
        string $provider,
        string $sourceType,
        string $recordId,
        WorkId $workId
    ): void {
        $this->works["{$provider}:{$sourceType}:{$recordId}"] = $workId;
    }

    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void
    {
        $this->editions["{$provider}:{$recordId}"] = $editionId;
    }
}

final class DiscoveryTextProvider implements BibliographicTextDiscoveryProvider
{
    private int $calls = 0;
    public function __construct(
        private string $key,
        private BibliographicProviderDiscoveryResult $result
    ) {}
    public function key(): string { return $this->key; }
    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult {
        ++$this->calls;
        return $this->result;
    }
    public function calls(): int { return $this->calls; }
}

final class DiscoverySnapshotRepository implements BibliographicDiscoverySnapshotRepository
{
    private ?BibliographicDiscoverySnapshot $saved = null;
    public function save(BibliographicDiscoverySnapshot $snapshot): void { $this->saved = $snapshot; }
    public function candidateForMaterialization(
        MetadataLookupId $discoveryId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        DateTimeImmutable $at
    ): ?BibliographicDiscoveryCandidate { return null; }
    public function saved(): ?BibliographicDiscoverySnapshot { return $this->saved; }
}

final readonly class DiscoveryLookupIds implements MetadataLookupIdGenerator
{
    public function next(): MetadataLookupId
    {
        return new MetadataLookupId("lookup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
    }
}

final readonly class DiscoveryClock implements MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-10T12:00:00+00:00");
    }
}

final readonly class DiscoveryTransactionManager implements TransactionManager
{
    public function run(callable $operation): mixed { return $operation(); }
}

final readonly class DiscoveryIsbnProvider implements MetadataProvider
{
    public function __construct(private string $key) {}
    public function key(): string { return $this->key; }
    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        return ProviderLookupResult::miss();
    }
}
