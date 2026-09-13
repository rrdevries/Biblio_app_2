<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorProviderIdentityLookup,
    BibliographicAuthorWorkMappingLookup,
    BibliographicAuthorWorkProviderPage,
    BibliographicAuthorWorkSearchContract,
    BibliographicAuthorWorkSearchCursor,
    BibliographicAuthorWorkSearchCursorCodec,
    BibliographicAuthorWorkSearchLane,
    BibliographicAuthorWorkSearchPage,
    BibliographicAuthorWorkSearchProvider,
    BibliographicAuthorWorkSearchRequest,
    BibliographicAuthorWorkSearchService,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderFailure,
    BibliographicWorkAuthor,
    BibliographicWorkReference,
    BibliographicWorkSelectorCodec,
    BibliographicWorkSearchResult
};
use Biblio\Core\Catalog\{AuthorId,WorkId};
use Biblio\Core\Exception\{AuthenticationException,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class BibliographicAuthorWorkSearchTest extends TestCase
{
    private const string CURSOR_SECRET = "test-works-by-selected-author-secret";

    public function testContractReusesWorkShapeWithoutEditionFields(): void
    {
        $author = BibliographicAuthorReference::canonical(new AuthorId("author-contract"));
        $work = $this->localWork("work-contract", "A Work", 0);
        $cursor = BibliographicAuthorWorkSearchCursor::forAuthor(
            $author,
            BibliographicAuthorWorkSearchLane::Local,
            1
        );
        $contract = new BibliographicAuthorWorkSearchContract(
            new BibliographicAuthorWorkSearchCursorCodec(self::CURSOR_SECRET),
            new BibliographicWorkSelectorCodec(self::CURSOR_SECRET . "-selector")
        );

        $payload = $contract->serialize(new BibliographicAuthorWorkSearchPage(
            $author,
            [$work],
            $cursor
        ));

        self::assertSame(["items", "next_cursor", "provider_attempts"], array_keys($payload));
        self::assertSame(
            [
                "result_id",
                "result_kind",
                "work_id",
                "work_selector",
                "provider_identity",
                "title",
                "authors",
                "series",
            ],
            array_keys($payload["items"][0])
        );
        self::assertNull($payload["items"][0]["provider_identity"]);
        self::assertArrayNotHasKey("isbn", $payload["items"][0]);
        self::assertArrayNotHasKey("publication_date", $payload["items"][0]);
        self::assertSame(
            1,
            $contract->decodeRequest($author, ["cursor" => $payload["next_cursor"]])
                ->cursor()?->nextOffset()
        );
    }

    public function testCursorFailsClosedForWrongAuthorMalformedAndTamperedValues(): void
    {
        $codec = new BibliographicAuthorWorkSearchCursorCodec(self::CURSOR_SECRET);
        $first = BibliographicAuthorReference::canonical(new AuthorId("author-one"));
        $encoded = $codec->encode(BibliographicAuthorWorkSearchCursor::forAuthor(
            $first,
            BibliographicAuthorWorkSearchLane::Local,
            10
        ));

        try {
            new BibliographicAuthorWorkSearchRequest(
                BibliographicAuthorReference::canonical(new AuthorId("author-two")),
                $codec->decode($encoded)
            );
            self::fail("Wrong-Author cursor was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        foreach (["not-a-cursor", $encoded . "x"] as $invalid) {
            try {
                $codec->decode($invalid);
                self::fail("Malformed or tampered cursor was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testContractRejectsUnknownAndCoercedRequestFields(): void
    {
        $contract = new BibliographicAuthorWorkSearchContract(
            new BibliographicAuthorWorkSearchCursorCodec(self::CURSOR_SECRET),
            new BibliographicWorkSelectorCodec(self::CURSOR_SECRET . "-selector")
        );
        $author = BibliographicAuthorReference::canonical(new AuthorId("author-request"));
        foreach ([["unknown" => true], ["cursor" => 10]] as $payload) {
            try {
                $contract->decodeRequest($author, $payload);
                self::fail("Invalid Works-by-Author request was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAuthenticationPrecedesProviderCalls(): void
    {
        $local = new AuthorWorkFakeProvider("local", []);
        $external = new AuthorWorkFakeProvider("open_library", []);
        $service = new BibliographicAuthorWorkSearchService(
            new ControllableAuthenticatedUser(),
            $local,
            $external,
            new AuthorWorkMappingFake(),
            new AuthorWorkMappingFake()
        );

        $this->expectException(AuthenticationException::class);
        try {
            $service->search(new BibliographicAuthorWorkSearchRequest(
                BibliographicAuthorReference::canonical(new AuthorId("author-auth"))
            ));
        } finally {
            self::assertSame(0, $local->calls);
            self::assertSame(0, $external->calls);
        }
    }

    public function testCanonicalAuthorUsesOnlyLocalWithoutProviderEvidence(): void
    {
        $local = new AuthorWorkFakeProvider("local", [
            $this->localWork("work-one", "First", 0),
        ]);
        $external = new AuthorWorkFakeProvider("open_library", []);

        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL1A"] = new AuthorId("author-paged");
        $page = $this->service($local, $external, $mapping)->search(
            new BibliographicAuthorWorkSearchRequest(
                BibliographicAuthorReference::canonical(new AuthorId("author-local"))
            )
        );

        self::assertCount(1, $page->items());
        self::assertSame(1, $local->calls);
        self::assertSame(0, $external->calls);
        self::assertSame([], $page->providerAttempts());
        self::assertNull($page->nextCursor());
    }

    public function testLocalContinuationPreventsEarlyExternalCall(): void
    {
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-paged"),
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL1A")
        );
        $items = [];
        for ($position = 0; $position < 10; $position++) {
            $items[] = $this->localWork("work-{$position}", sprintf("Work %02d", $position), $position);
        }
        $local = new AuthorWorkFakeProvider("local", $items, 10);
        $external = new AuthorWorkFakeProvider("open_library", [
            $this->externalWork("/works/OL11W", "External", 0),
        ]);
        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL1A"] = new AuthorId("author-paged");

        $page = $this->service($local, $external, $mapping)->search(
            new BibliographicAuthorWorkSearchRequest($author)
        );

        self::assertCount(10, $page->items());
        self::assertSame(BibliographicAuthorWorkSearchLane::Local, $page->nextCursor()?->lane());
        self::assertSame(10, $page->nextCursor()?->nextOffset());
        self::assertSame(0, $external->calls);
    }

    public function testProviderAuthorUsesExternalIdentityAndTypedMiss(): void
    {
        $local = new AuthorWorkFakeProvider("local", []);
        $external = new AuthorWorkFakeProvider("open_library", []);
        $author = BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL2A")
        );

        $page = $this->service($local, $external)->search(
            new BibliographicAuthorWorkSearchRequest($author)
        );

        self::assertSame(0, $local->calls);
        self::assertSame(1, $external->calls);
        self::assertSame($author->cursorContextId(), $external->author?->cursorContextId());
        self::assertSame(ProviderLookupStatus::Miss, $page->providerAttempts()[0]->status());
        self::assertNull($page->providerAttempts()[0]->failureReason());
    }

    public function testFullFinalLocalPageHandsOffToExternalOffsetZeroWithoutEarlyCall(): void
    {
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-handoff"),
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL20A")
        );
        $items = [];
        for ($position = 0; $position < 10; $position++) {
            $items[] = $this->localWork(
                "handoff-work-{$position}",
                sprintf("Handoff %02d", $position),
                $position
            );
        }
        $local = new AuthorWorkFakeProvider("local", $items);
        $external = new AuthorWorkFakeProvider("open_library", [
            $this->externalWork("/works/OL200W", "External continuation", 0),
        ]);
        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL20A"] = new AuthorId("author-handoff");
        $service = $this->service($local, $external, $mapping);

        $first = $service->search(new BibliographicAuthorWorkSearchRequest($author));
        self::assertSame(BibliographicAuthorWorkSearchLane::External, $first->nextCursor()?->lane());
        self::assertSame(0, $first->nextCursor()?->nextOffset());
        self::assertSame(0, $external->calls);

        $second = $service->search(new BibliographicAuthorWorkSearchRequest(
            $author,
            $first->nextCursor()
        ));
        self::assertCount(1, $second->items());
        self::assertSame(1, $external->calls);
        self::assertSame(0, $external->offset);
    }

    public function testProviderFailurePreservesLocalWorks(): void
    {
        $local = new AuthorWorkFakeProvider("local", [
            $this->localWork("work-local", "Local", 0),
        ]);
        $external = new AuthorWorkFakeProvider("open_library", []);
        $external->failure = new BibliographicSearchProviderFailure(
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Timeout
        );
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-mapped"),
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL3A")
        );

        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL3A"] = new AuthorId("author-mapped");
        $page = $this->service($local, $external, $mapping)->search(
            new BibliographicAuthorWorkSearchRequest($author)
        );

        self::assertSame("work-local", $page->items()[0]->reference()->workId()?->value());
        self::assertSame(ProviderLookupStatus::Unavailable, $page->providerAttempts()[0]->status());
        self::assertSame(ProviderFailureReason::Timeout, $page->providerAttempts()[0]->failureReason());
    }

    public function testStrongMappedIdentityDeduplicatesButEqualTitleDoesNot(): void
    {
        $local = new AuthorWorkFakeProvider("local", [
            $this->localWork("work-local", "Same title", 0),
        ]);
        $external = new AuthorWorkFakeProvider("open_library", [
            $this->externalWork("/works/OL4W", "Same title", 0),
            $this->externalWork("/works/OL5W", "Same title", 1),
        ]);
        $mapping = new AuthorWorkMappingFake();
        $mapping->mapped["/works/OL4W"] = new WorkId("work-local");
        $mapping->authors["/authors/OL4A"] = new AuthorId("author-dedup");
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-dedup"),
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL4A")
        );

        $page = $this->service($local, $external, $mapping)->search(
            new BibliographicAuthorWorkSearchRequest($author)
        );

        self::assertCount(2, $page->items());
        self::assertSame("work-local", $page->items()[0]->reference()->workId()?->value());
        self::assertSame(
            "/works/OL5W",
            $page->items()[1]->reference()->providerIdentity()?->providerRecordId()
        );
        self::assertSame(["/works/OL4W", "/works/OL5W"], $mapping->lastRecordIds);
    }

    public function testUnsupportedProviderReferenceFailsClosed(): void
    {
        $this->expectException(ValidationException::class);
        $this->service(
            new AuthorWorkFakeProvider("local", []),
            new AuthorWorkFakeProvider("open_library", [])
        )->search(new BibliographicAuthorWorkSearchRequest(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("another_provider", "author-1")
            )
        ));
    }

    public function testCompositeAuthorMappingIsRevalidatedAtUseTime(): void
    {
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-current"),
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL77A"
            )
        );
        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL77A"] = new AuthorId("author-other");
        $local = new AuthorWorkFakeProvider("local", [
            $this->localWork("work-must-not-be-read", "Must not be read", 0),
        ], 1);

        $this->expectException(ValidationException::class);
        try {
            $this->service(
                $local,
                new AuthorWorkFakeProvider("open_library", []),
                $mapping
            )->search(new BibliographicAuthorWorkSearchRequest($author));
        } finally {
            self::assertSame(0, $local->calls);
        }
    }

    public function testCompositeAuthorFailsClosedWhenASecondCurrentClaimAppears(): void
    {
        $author = BibliographicAuthorReference::canonical(
            new AuthorId("author-ambiguous"),
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL88A"
            )
        );
        $mapping = new AuthorWorkMappingFake();
        $mapping->authors["/authors/OL88A"] = new AuthorId("author-ambiguous");
        $mapping->authors["/authors/OL89A"] = new AuthorId("author-ambiguous");
        $local = new AuthorWorkFakeProvider("local", [
            $this->localWork("work-must-not-be-read", "Must not be read", 0),
        ], 1);
        $external = new AuthorWorkFakeProvider("open_library", []);

        $this->expectException(ValidationException::class);
        try {
            $this->service($local, $external, $mapping)->search(
                new BibliographicAuthorWorkSearchRequest($author)
            );
        } finally {
            self::assertSame(0, $local->calls);
            self::assertSame(0, $external->calls);
        }
    }

    public function testMismatchedExternalWorkProviderFailsClosed(): void
    {
        $wrong = new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work("another_provider", "work-1")
            ),
            "Wrong provider",
            [],
            [],
            0
        );
        $this->expectException(ValidationException::class);
        $this->service(
            new AuthorWorkFakeProvider("local", []),
            new AuthorWorkFakeProvider("open_library", [$wrong])
        )->search(new BibliographicAuthorWorkSearchRequest(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("open_library", "/authors/OL30A")
            )
        ));
    }

    private function service(
        AuthorWorkFakeProvider $local,
        AuthorWorkFakeProvider $external,
        ?AuthorWorkMappingFake $mapping = null
    ): BibliographicAuthorWorkSearchService {
        return new BibliographicAuthorWorkSearchService(
            new ControllableAuthenticatedUser(new UserId("author-work-user")),
            $local,
            $external,
            $mapping ?? new AuthorWorkMappingFake(),
            $mapping ?? new AuthorWorkMappingFake()
        );
    }

    private function localWork(string $id, string $title, int $order): BibliographicWorkSearchResult
    {
        return new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId($id)),
            $title,
            [new BibliographicWorkAuthor("Local Author", new AuthorId("author-local"))],
            [],
            $order
        );
    }

    private function externalWork(string $id, string $title, int $order): BibliographicWorkSearchResult
    {
        return new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work("open_library", $id)
            ),
            $title,
            [],
            [],
            $order
        );
    }
}

final class AuthorWorkFakeProvider implements BibliographicAuthorWorkSearchProvider
{
    public int $calls = 0;
    public ?BibliographicAuthorReference $author = null;
    public int $offset = -1;
    public int $limit = -1;
    public ?BibliographicSearchProviderFailure $failure = null;

    /** @param list<BibliographicWorkSearchResult> $items */
    public function __construct(
        private string $providerKey,
        private array $items,
        private ?int $nextOffset = null
    ) {
    }

    public function key(): string { return $this->providerKey; }

    public function searchWorksForAuthor(
        BibliographicAuthorReference $author,
        int $offset,
        int $limit
    ): BibliographicAuthorWorkProviderPage {
        $this->calls++;
        $this->author = $author;
        $this->offset = $offset;
        $this->limit = $limit;
        if ($this->failure !== null) { throw $this->failure; }
        return new BibliographicAuthorWorkProviderPage($this->items, $this->nextOffset);
    }
}

final class AuthorWorkMappingFake implements
    BibliographicAuthorWorkMappingLookup,
    BibliographicAuthorProviderIdentityLookup
{
    /** @var array<string,WorkId> */ public array $mapped = [];
    /** @var array<string,AuthorId> */ public array $authors = [];
    /** @var list<string> */ public array $lastRecordIds = [];

    public function mappedWorksForAuthor(
        AuthorId $authorId,
        string $providerKey,
        array $providerWorkRecordIds
    ): array {
        $this->lastRecordIds = $providerWorkRecordIds;
        return array_intersect_key($this->mapped, array_fill_keys($providerWorkRecordIds, true));
    }

    public function mappedAuthors(string $providerKey, array $providerAuthorRecordIds): array
    {
        return array_intersect_key(
            $this->authors,
            array_fill_keys($providerAuthorRecordIds, true)
        );
    }

    public function providerAuthorIdentities(string $providerKey, array $authorIds): array
    {
        $requested = array_fill_keys(array_map(
            static fn (AuthorId $authorId): string => $authorId->value(),
            $authorIds
        ), true);
        $result = [];
        foreach ($this->authors as $recordId => $authorId) {
            if (isset($requested[$authorId->value()])) {
                $result[$authorId->value()][] = BibliographicProviderEntityIdentity::author(
                    $providerKey,
                    $recordId
                );
            }
        }
        return $result;
    }
}
