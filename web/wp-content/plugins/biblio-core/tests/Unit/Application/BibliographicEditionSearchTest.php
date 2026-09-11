<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicEditionProviderPage,
    BibliographicEditionReference,
    BibliographicEditionSearchContract,
    BibliographicEditionSearchCursor,
    BibliographicEditionSearchCursorCodec,
    BibliographicEditionSearchLane,
    BibliographicEditionSearchPage,
    BibliographicEditionSearchProvider,
    BibliographicEditionSearchRequest,
    BibliographicEditionSearchResult,
    BibliographicEditionSearchService,
    BibliographicExternalEditionSearchProvider,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderFailure,
    BibliographicWorkProviderIdentityLookup,
    BibliographicWorkReference
};
use Biblio\Core\Catalog\{
    CanonicalIsbnIdentity,
    Edition,
    EditionId,
    EditionIdentifierClaimRepository,
    EditionIsbnMetadata,
    EditionRepository,
    Isbn10,
    Isbn13,
    WorkId
};
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BibliographicEditionSearchTest extends TestCase
{
    private const string CURSOR_SECRET = "test-editions-for-work-cursor-secret";

    public function testContractPreservesNullableEditionMetadataAndMaterializationHandoff(): void
    {
        $work = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $edition = $this->external($work, "/books/OL1M", "Concrete title", 0);
        $cursor = BibliographicEditionSearchCursor::forWork(
            $work,
            BibliographicEditionSearchLane::External,
            1
        );
        $contract = new BibliographicEditionSearchContract(
            new BibliographicEditionSearchCursorCodec(self::CURSOR_SECRET)
        );

        $payload = $contract->serialize(new BibliographicEditionSearchPage(
            $work,
            [$edition],
            $cursor
        ));

        self::assertSame(["items", "next_cursor", "provider_attempts"], array_keys($payload));
        self::assertSame("/books/OL1M", $payload["items"][0]["provider_identity"]["record_id"]);
        self::assertSame("/works/OL1W", $payload["items"][0]["provider_work_identity"]["record_id"]);
        self::assertNull($payload["items"][0]["edition_id"]);
        self::assertNull($payload["items"][0]["isbn_13"]);
        self::assertSame([], $payload["items"][0]["languages"]);
        self::assertTrue($payload["items"][0]["requires_materialization"]);
        self::assertTrue($payload["items"][0]["can_add_edition_specific"]);
        $request = $contract->decodeRequest($work, ["cursor" => $payload["next_cursor"]]);
        self::assertSame(1, $request->cursor()?->nextOffset());
    }

    public function testCursorIsStrictAndBoundToTheExactSelectedWorkReference(): void
    {
        $first = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $second = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL2W")
        );
        $codec = new BibliographicEditionSearchCursorCodec(self::CURSOR_SECRET);
        $encoded = $codec->encode(BibliographicEditionSearchCursor::forWork(
            $first,
            BibliographicEditionSearchLane::External,
            10
        ));

        $this->expectException(ValidationException::class);
        new BibliographicEditionSearchRequest($second, $codec->decode($encoded));
    }

    public function testMalformedCursorFailsClosed(): void
    {
        $this->expectException(ValidationException::class);
        (new BibliographicEditionSearchCursorCodec(self::CURSOR_SECRET))->decode("not-a-cursor");
    }

    public function testTamperedCursorFailsClosed(): void
    {
        $work = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $codec = new BibliographicEditionSearchCursorCodec(self::CURSOR_SECRET);
        $encoded = $codec->encode(BibliographicEditionSearchCursor::forWork(
            $work,
            BibliographicEditionSearchLane::External,
            10
        ));

        $this->expectException(ValidationException::class);
        $codec->decode(substr($encoded, 0, -1) . ($encoded[-1] === "a" ? "b" : "a"));
    }

    public function testCursorRejectsAnotherProviderReference(): void
    {
        $openLibrary = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $otherProvider = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("other_provider", "/works/OL1W")
        );
        $cursor = BibliographicEditionSearchCursor::forWork(
            $openLibrary,
            BibliographicEditionSearchLane::External,
            10
        );

        $this->expectException(ValidationException::class);
        new BibliographicEditionSearchRequest($otherProvider, $cursor);
    }

    public function testCanonicalWorkCombinesLocalThenOneMappedExternalLaneAndStronglyDeduplicates(): void
    {
        $workId = new WorkId("work-local");
        $work = BibliographicWorkReference::canonical($workId);
        $isbn = $this->isbn();
        $localEdition = new Edition(
            new EditionId("edition-local"),
            $workId,
            "Same visible title",
            $isbn->metadata()
        );
        $local = new EditionLocalFakeProvider([
            $this->local($work, $localEdition, 0),
        ]);
        $external = new EditionExternalFakeProvider([
            $this->external($work, "/books/OL-MAPPED1M", "Mapped duplicate", 0),
            $this->external($work, "/books/OL-ISBN2M", "ISBN duplicate", 1, $isbn),
            $this->external($work, "/books/OL-OTHER3M", "Same visible title", 2),
            $this->external($work, "/books/OL-OTHER3M", "Same provider duplicate", 3),
        ]);
        $identities = new EditionIdentityFake();
        $identities->workIdentities = [
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W"),
        ];
        $identities->editionMappings["open_library\0/books/OL-MAPPED1M"] = $localEdition->id();
        $claims = new EditionClaimFake();
        $claims->claims[$isbn->isbn13()->value()] = $localEdition->id();
        $editions = new EditionRepositoryFake([$localEdition]);

        $page = $this->service($local, $external, $identities, $claims, $editions)
            ->search(new BibliographicEditionSearchRequest($work));

        self::assertSame(1, $local->calls);
        self::assertSame(1, $external->calls);
        self::assertSame("/works/OL1W", $external->providerWork?->providerRecordId());
        self::assertCount(2, $page->items());
        self::assertSame("edition-local", $page->items()[0]->reference()->editionId()?->value());
        self::assertSame("/books/OL-OTHER3M", $page->items()[1]->reference()->providerIdentity()?->providerRecordId());
        self::assertSame(ProviderLookupStatus::Candidates, $page->providerAttempts()[0]->status());
    }

    public function testExternalFailureRetainsLocalEditionsAndTypedFailure(): void
    {
        $work = BibliographicWorkReference::canonical(new WorkId("work-failure"));
        $localEdition = new Edition(
            new EditionId("edition-safe"),
            new WorkId("work-failure"),
            "Local remains"
        );
        $local = new EditionLocalFakeProvider([$this->local($work, $localEdition, 0)]);
        $external = new EditionExternalFakeProvider([]);
        $external->failure = new BibliographicSearchProviderFailure(
            ProviderLookupStatus::RateLimited,
            ProviderFailureReason::RateLimited
        );
        $identities = new EditionIdentityFake();
        $identities->workIdentities = [
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL2W"),
        ];

        $page = $this->service(
            $local,
            $external,
            $identities,
            new EditionClaimFake(),
            new EditionRepositoryFake([$localEdition])
        )->search(new BibliographicEditionSearchRequest($work));

        self::assertCount(1, $page->items());
        self::assertSame(ProviderLookupStatus::RateLimited, $page->providerAttempts()[0]->status());
        self::assertSame(ProviderFailureReason::RateLimited, $page->providerAttempts()[0]->failureReason());
        self::assertNull($page->nextCursor());
    }

    public function testNoOrAmbiguousProviderWorkMappingNeverGuessesAnExternalLane(): void
    {
        foreach ([[], [
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W"),
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL2W"),
        ]] as $workIdentities) {
            $work = BibliographicWorkReference::canonical(new WorkId("work-no-guess"));
            $identities = new EditionIdentityFake();
            $identities->workIdentities = $workIdentities;
            $external = new EditionExternalFakeProvider([]);
            $page = $this->service(
                new EditionLocalFakeProvider([]),
                $external,
                $identities,
                new EditionClaimFake(),
                new EditionRepositoryFake([])
            )->search(new BibliographicEditionSearchRequest($work));
            self::assertSame([], $page->items());
            self::assertSame(0, $external->calls);
        }
    }

    public function testExternalWorkUsesOnlyExternalLaneAndRequiresAuthentication(): void
    {
        $work = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL3W")
        );
        $local = new EditionLocalFakeProvider([]);
        $external = new EditionExternalFakeProvider([$this->external(
            $work,
            "/books/OL3M",
            "ISBN-less Edition",
            0
        )]);
        $authentication = new ControllableAuthenticatedUser();
        $service = new BibliographicEditionSearchService(
            $authentication,
            $local,
            $external,
            new EditionIdentityFake(),
            new EditionIdentityFake(),
            new EditionClaimFake(),
            new EditionRepositoryFake([])
        );

        try {
            $service->search(new BibliographicEditionSearchRequest($work));
            self::fail("Unauthenticated Edition search was accepted.");
        } catch (AuthenticationException) {
            self::addToAssertionCount(1);
        }
        $authentication->authenticateAs(new UserId("edition-search-user"));
        $page = $service->search(new BibliographicEditionSearchRequest($work));
        self::assertSame(0, $local->calls);
        self::assertSame(1, $external->calls);
        self::assertCount(1, $page->items());
        self::assertNull($page->items()[0]->isbn());
    }

    public function testFullLocalPageHandsOffToExternalOffsetZeroWithoutEarlyHttpCall(): void
    {
        $workId = new WorkId("work-lane-handoff");
        $work = BibliographicWorkReference::canonical($workId);
        $localItems = [];
        $editions = [];
        for ($position = 0; $position < 10; $position++) {
            $edition = new Edition(
                new EditionId("lane-edition-{$position}"),
                $workId,
                sprintf("Local %02d", $position)
            );
            $editions[] = $edition;
            $localItems[] = $this->local($work, $edition, $position);
        }
        $local = new EditionLocalFakeProvider($localItems);
        $external = new EditionExternalFakeProvider([
            $this->external(
                $work,
                "/books/OL40M",
                "External continuation",
                0,
                null,
                BibliographicProviderEntityIdentity::work("open_library", "/works/OL4W")
            ),
        ]);
        $identities = new EditionIdentityFake();
        $identities->workIdentities = [
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL4W"),
        ];
        $service = $this->service(
            $local,
            $external,
            $identities,
            new EditionClaimFake(),
            new EditionRepositoryFake($editions)
        );

        $first = $service->search(new BibliographicEditionSearchRequest($work));
        self::assertCount(10, $first->items());
        self::assertSame(BibliographicEditionSearchLane::External, $first->nextCursor()?->lane());
        self::assertSame(0, $first->nextCursor()?->nextOffset());
        self::assertSame(0, $external->calls);

        $second = $service->search(new BibliographicEditionSearchRequest(
            $work,
            $first->nextCursor()
        ));
        self::assertCount(1, $second->items());
        self::assertSame(1, $external->calls);
        self::assertNull($second->nextCursor());
    }

    public function testValidEmptyExternalPageIsTypedNormalMiss(): void
    {
        $work = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL5W")
        );
        $page = $this->service(
            new EditionLocalFakeProvider([]),
            new EditionExternalFakeProvider([]),
            new EditionIdentityFake(),
            new EditionClaimFake(),
            new EditionRepositoryFake([])
        )->search(new BibliographicEditionSearchRequest($work));

        self::assertSame([], $page->items());
        self::assertNull($page->nextCursor());
        self::assertSame(ProviderLookupStatus::Miss, $page->providerAttempts()[0]->status());
        self::assertNull($page->providerAttempts()[0]->failureReason());
    }

    private function service(
        EditionLocalFakeProvider $local,
        EditionExternalFakeProvider $external,
        EditionIdentityFake $identities,
        EditionClaimFake $claims,
        EditionRepositoryFake $editions
    ): BibliographicEditionSearchService {
        return new BibliographicEditionSearchService(
            new ControllableAuthenticatedUser(new UserId("edition-search-user")),
            $local,
            $external,
            $identities,
            $identities,
            $claims,
            $editions
        );
    }

    private function local(
        BibliographicWorkReference $work,
        Edition $edition,
        int $order
    ): BibliographicEditionSearchResult {
        return new BibliographicEditionSearchResult(
            BibliographicEditionReference::canonical($edition->id()),
            $work,
            $edition->title(),
            CanonicalIsbnIdentity::fromMetadata($edition->isbnMetadata()),
            null,
            [],
            [],
            [],
            null,
            null,
            null,
            $order
        );
    }

    private function external(
        BibliographicWorkReference $work,
        string $recordId,
        string $title,
        int $order,
        ?CanonicalIsbnIdentity $isbn = null,
        ?BibliographicProviderEntityIdentity $providerWork = null
    ): BibliographicEditionSearchResult {
        $providerWork ??= $work->providerIdentity()
            ?? BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W");
        return new BibliographicEditionSearchResult(
            BibliographicEditionReference::external(
                BibliographicProviderEntityIdentity::edition("open_library", $recordId)
            ),
            $work,
            $title,
            $isbn,
            null,
            [],
            [],
            [],
            null,
            null,
            null,
            $order,
            new DateTimeImmutable("2026-09-11T10:00:00Z"),
            MetadataMatchMethod::TextSearch,
            $providerWork
        );
    }

    private function isbn(): CanonicalIsbnIdentity
    {
        return new CanonicalIsbnIdentity(new Isbn13("9780441172719"), new Isbn10("0441172717"));
    }
}

final class EditionLocalFakeProvider implements BibliographicEditionSearchProvider
{
    public int $calls = 0;
    /** @param list<BibliographicEditionSearchResult> $items */
    public function __construct(private array $items, private ?int $nextOffset = null) {}
    public function key(): string { return "local"; }
    public function searchEditions(BibliographicWorkReference $work, int $offset, int $limit): BibliographicEditionProviderPage
    {
        $this->calls++;
        return new BibliographicEditionProviderPage($this->items, $this->nextOffset);
    }
}

final class EditionExternalFakeProvider implements BibliographicExternalEditionSearchProvider
{
    public int $calls = 0;
    public ?BibliographicProviderEntityIdentity $providerWork = null;
    public ?BibliographicSearchProviderFailure $failure = null;
    /** @param list<BibliographicEditionSearchResult> $items */
    public function __construct(private array $items, private ?int $nextOffset = null) {}
    public function key(): string { return "open_library"; }
    public function searchEditionsForProviderWork(
        BibliographicWorkReference $parentWork,
        BibliographicProviderEntityIdentity $providerWork,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage {
        $this->calls++;
        $this->providerWork = $providerWork;
        if ($this->failure !== null) { throw $this->failure; }
        return new BibliographicEditionProviderPage($this->items, $this->nextOffset);
    }
}

final class EditionIdentityFake implements
    BibliographicProviderIdentityRepository,
    BibliographicWorkProviderIdentityLookup
{
    /** @var list<BibliographicProviderEntityIdentity> */ public array $workIdentities = [];
    /** @var array<string,EditionId> */ public array $editionMappings = [];
    public function providerWorkIdentities(WorkId $workId, string $providerKey): array { return $this->workIdentities; }
    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId { return null; }
    public function findEdition(string $provider, string $recordId): ?EditionId
    {
        return $this->editionMappings["{$provider}\0{$recordId}"] ?? null;
    }
    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void {}
    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void {}
}

final class EditionClaimFake implements EditionIdentifierClaimRepository
{
    /** @var array<string,EditionId> */ public array $claims = [];
    public function findByCanonicalIsbn13(Isbn13 $isbn13): ?EditionId { return $this->claims[$isbn13->value()] ?? null; }
    public function claim(Isbn13 $isbn13, EditionId $editionId): void {}
}

final class EditionRepositoryFake implements EditionRepository
{
    /** @var array<string,Edition> */ private array $editions = [];
    /** @param list<Edition> $editions */
    public function __construct(array $editions)
    {
        foreach ($editions as $edition) { $this->editions[$edition->id()->value()] = $edition; }
    }
    public function find(EditionId $editionId): ?Edition { return $this->editions[$editionId->value()] ?? null; }
}
