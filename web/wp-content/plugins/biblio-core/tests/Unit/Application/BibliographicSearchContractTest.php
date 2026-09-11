<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorSearchPage,
    BibliographicAuthorSearchProvider,
    BibliographicAuthorSearchResult,
    BibliographicAuthorSelectorCodec,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderAttempt,
    BibliographicSearchCursor,
    BibliographicSearchCursorCodec,
    BibliographicSearchGroup,
    BibliographicSearchResultKind,
    BibliographicTextSearchContract,
    BibliographicTextSearchQuery,
    BibliographicTextSearchRequest,
    BibliographicTextSearchResult,
    BibliographicWorkAuthor,
    BibliographicWorkReference,
    BibliographicWorkSearchPage,
    BibliographicWorkSearchProvider,
    BibliographicWorkSearchResult,
    BibliographicWorkSeriesContext
};
use Biblio\Core\Application\Metadata\{ProviderFailureReason,ProviderLookupStatus};
use Biblio\Core\Catalog\{AuthorId,SeriesId,SeriesPosition,WorkId};
use Biblio\Core\Exception\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BibliographicSearchContractTest extends TestCase
{
    private const string CURSOR_SECRET = "test-bibliographic-search-cursor-secret";
    private const string SELECTOR_SECRET = "test-bibliographic-author-selector-secret";

    public function testTypedPagesSerializeSeparateAuthorsAndWorksWithIndependentCursors(): void
    {
        $query = new BibliographicTextSearchQuery("  Ursula   Le Guin ");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("open_library", "/authors/OL1A")
            ),
            "Ursula K. Le Guin",
            0
        );
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId("work-dispossessed")),
            "The Dispossessed",
            [new BibliographicWorkAuthor("Ursula K. Le Guin", new AuthorId("author-le-guin"))],
            [new BibliographicWorkSeriesContext(
                "Hainish Cycle",
                new SeriesId("series-hainish"),
                SeriesPosition::known("6")
            )],
            0
        );
        $result = new BibliographicTextSearchResult(
            $query,
            new BibliographicAuthorSearchPage($query, [$author], $author->cursor($query)),
            new BibliographicWorkSearchPage($query, [$work], $work->cursor($query)),
            [new BibliographicSearchProviderAttempt(
                "open_library",
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Timeout
            )],
            [new BibliographicSearchProviderAttempt(
                "open_library",
                ProviderLookupStatus::Candidates
            )]
        );
        $contract = $this->contract();

        $payload = $contract->serialize($result);

        self::assertSame(["query", "authors", "works"], array_keys($payload));
        self::assertSame("Ursula Le Guin", $payload["query"]);
        self::assertSame(
            ["items", "next_cursor", "provider_attempts"],
            array_keys($payload["authors"])
        );
        self::assertSame(
            ["items", "next_cursor", "provider_attempts"],
            array_keys($payload["works"])
        );
        self::assertSame([
            "provider_key" => "open_library",
            "status" => "unavailable",
            "failure_reason" => "timeout",
        ], $payload["authors"]["provider_attempts"][0]);
        self::assertSame([
            "provider_key" => "open_library",
            "status" => "candidates",
            "failure_reason" => null,
        ], $payload["works"]["provider_attempts"][0]);
        self::assertSame("external_candidate", $payload["authors"]["items"][0]["result_kind"]);
        self::assertNull($payload["authors"]["items"][0]["author_id"]);
        $selectedAuthor = (new BibliographicAuthorSelectorCodec(
            self::SELECTOR_SECRET
        ))->decode($payload["authors"]["items"][0]["author_selector"]);
        self::assertSame(
            "/authors/OL1A",
            $selectedAuthor->providerIdentity()?->providerRecordId()
        );
        self::assertSame("local_canonical", $payload["works"]["items"][0]["result_kind"]);
        self::assertSame("work-dispossessed", $payload["works"]["items"][0]["work_id"]);
        self::assertSame(
            ["result_id", "result_kind", "work_id", "title", "authors", "series"],
            array_keys($payload["works"]["items"][0])
        );
        self::assertArrayNotHasKey("isbn", $payload["works"]["items"][0]);
        self::assertArrayNotHasKey("publisher", $payload["works"]["items"][0]);
        self::assertNotSame(
            $payload["authors"]["next_cursor"],
            $payload["works"]["next_cursor"]
        );

        $request = $contract->decodeRequest([
            "query" => "Ursula Le Guin",
            "author_cursor" => $payload["authors"]["next_cursor"],
            "work_cursor" => $payload["works"]["next_cursor"],
        ]);
        self::assertSame(BibliographicSearchGroup::Authors, $request->authorCursor()?->group());
        self::assertSame(BibliographicSearchGroup::Works, $request->workCursor()?->group());

        $firstPage = $contract->decodeRequest(["query" => "Ursula Le Guin"]);
        self::assertNull($firstPage->authorCursor());
        self::assertNull($firstPage->workCursor());
        self::assertSame(
            "9780441172718",
            $contract->decodeRequest(["query" => "9780441172718"])->query()->value()
        );
    }

    #[DataProvider("emptyGroupCases")]
    public function testGroupsAreIndependentlyEmpty(bool $authors, bool $works): void
    {
        $query = new BibliographicTextSearchQuery("Octavia Butler");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-butler")),
            "Octavia E. Butler",
            0
        );
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId("work-kindred")),
            "Kindred",
            [new BibliographicWorkAuthor("Octavia E. Butler", new AuthorId("author-butler"))],
            [],
            0
        );
        $result = new BibliographicTextSearchResult(
            $query,
            new BibliographicAuthorSearchPage($query, $authors ? [$author] : [], null),
            new BibliographicWorkSearchPage($query, $works ? [$work] : [], null)
        );
        $payload = $this->contract()->serialize($result);

        self::assertCount($authors ? 1 : 0, $payload["authors"]["items"]);
        self::assertCount($works ? 1 : 0, $payload["works"]["items"]);
        self::assertNull($payload["authors"]["next_cursor"]);
        self::assertNull($payload["works"]["next_cursor"]);
    }

    /** @return iterable<string,array{bool,bool}> */
    public static function emptyGroupCases(): iterable
    {
        yield "Authors empty" => [false, true];
        yield "Works empty" => [true, false];
        yield "Both empty" => [false, false];
    }

    public function testCursorCodecIsVersionedStrictAndQueryAndGroupBound(): void
    {
        $query = new BibliographicTextSearchQuery("N. K. Jemisin");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-jemisin")),
            "N. K. Jemisin",
            0
        );
        $codec = new BibliographicSearchCursorCodec(self::CURSOR_SECRET);
        $encoded = $codec->encode($author->cursor($query));
        $decoded = $codec->decode($encoded);

        self::assertSame("N. K. Jemisin", $decoded->query()->value());
        self::assertSame(BibliographicSearchGroup::Authors, $decoded->group());
        self::assertSame($author->reference()->resultId(), $decoded->resultId());

        $this->expectException(ValidationException::class);
        new BibliographicTextSearchRequest(
            new BibliographicTextSearchQuery("Ancillary Justice"),
            $decoded
        );
    }

    public function testTypedTextSearchQueryRejectsValidIsbnDirectly(): void
    {
        $this->expectException(ValidationException::class);
        new BibliographicTextSearchQuery("9780441172719");
    }

    public function testWrongGroupCursorFailsClosed(): void
    {
        $query = new BibliographicTextSearchQuery("Murderbot");
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId("work-murderbot")),
            "All Systems Red",
            [new BibliographicWorkAuthor("Martha Wells")],
            [],
            0
        );

        $this->expectException(ValidationException::class);
        new BibliographicTextSearchRequest($query, $work->cursor($query));
    }

    public function testTamperedCursorFailsClosed(): void
    {
        $query = new BibliographicTextSearchQuery("Parable");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-oeb")),
            "Octavia E. Butler",
            0
        );
        $codec = new BibliographicSearchCursorCodec(self::CURSOR_SECRET);
        $encoded = $codec->encode($author->cursor($query));
        $tampered = substr($encoded, 0, -1) . (str_ends_with($encoded, "A") ? "B" : "A");

        $this->expectException(ValidationException::class);
        $codec->decode($tampered);
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider("invalidSignedCursorPayloads")]
    public function testValidlySignedCursorRejectsUnknownDiscriminatorAndFields(
        array $payload
    ): void {
        $this->expectException(ValidationException::class);
        (new BibliographicSearchCursorCodec(self::CURSOR_SECRET))->decode(
            $this->signCursorPayload($payload)
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSignedCursorPayloads(): iterable
    {
        $valid = [
            "v" => 1,
            "q" => "Dune",
            "group" => "authors",
            "kind" => "local_canonical",
            "order" => 0,
            "result_id" => "search-author-" . str_repeat("a", 64),
        ];
        yield "unknown result discriminator" => [[...$valid, "kind" => "provider_winner"]];
        yield "unknown cursor field" => [[...$valid, "provider" => "open_library"]];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider("malformedRequests")]
    public function testStrictRequestAndCursorDecodingFailsClosed(array $payload): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->decodeRequest($payload);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function malformedRequests(): iterable
    {
        yield "unknown field" => [[
            "query" => "Dune",
            "author_cursor" => null,
            "work_cursor" => null,
            "provider" => "open_library",
        ]];
        yield "ISBN excluded" => [["query" => "9780441172719"]];
        yield "coerced cursor" => [[
            "query" => "Dune",
            "author_cursor" => false,
            "work_cursor" => null,
        ]];
        yield "malformed cursor" => [[
            "query" => "Dune",
            "author_cursor" => "not+base64",
            "work_cursor" => null,
        ]];
        yield "empty query" => [[
            "query" => " ",
            "author_cursor" => null,
            "work_cursor" => null,
        ]];
    }

    public function testPagesRequireDeterministicLocalFirstOrderAndMatchingContinuation(): void
    {
        $query = new BibliographicTextSearchQuery("Earthsea");
        $external = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author("open_library", "/authors/OL2A")
            ),
            "Ursula Le Guin",
            0
        );
        $local = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-local")),
            "Ursula K. Le Guin",
            99
        );

        $this->expectException(ValidationException::class);
        new BibliographicAuthorSearchPage($query, [$external, $local], null);
    }

    public function testStrongIdentityDuplicatesAreRejectedWithoutFuzzyDeduplication(): void
    {
        $query = new BibliographicTextSearchQuery("The Left Hand of Darkness");
        $canonical = new WorkId("work-left-hand");
        $first = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical($canonical),
            "The Left Hand of Darkness",
            [new BibliographicWorkAuthor("Ursula K. Le Guin")],
            [],
            0
        );
        $mapped = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(
                $canonical,
                BibliographicProviderEntityIdentity::work("open_library", "/works/OL3W")
            ),
            "Left Hand of Darkness",
            [new BibliographicWorkAuthor("Ursula Le Guin")],
            [],
            1
        );
        self::assertSame($first->reference()->resultId(), $mapped->reference()->resultId());

        try {
            new BibliographicWorkSearchPage($query, [$first, $mapped], null);
            self::fail("A duplicate canonical Work identity was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }

        $lookalike = new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work("open_library", "/works/OL4W")
            ),
            "The Left Hand of Darkness",
            [new BibliographicWorkAuthor("Ursula K. Le Guin")],
            [],
            0
        );
        self::assertCount(
            2,
            (new BibliographicWorkSearchPage($query, [$first, $lookalike], null))->items()
        );

        $mappedExternal = new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work("open_library", "/works/OL3W")
            ),
            "The Left Hand of Darkness",
            [new BibliographicWorkAuthor("Ursula K. Le Guin")],
            [],
            0
        );
        try {
            new BibliographicWorkSearchPage($query, [$mapped, $mappedExternal], null);
            self::fail("A mapped provider Work duplicate was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }

        $authorIdentity = BibliographicProviderEntityIdentity::author(
            "open_library",
            "/authors/OL6A"
        );
        $mappedAuthor = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(
                new AuthorId("author-le-guin"),
                $authorIdentity
            ),
            "Ursula K. Le Guin",
            0
        );
        $externalAuthor = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external($authorIdentity),
            "Ursula Le Guin",
            0
        );
        $this->expectException(ValidationException::class);
        new BibliographicAuthorSearchPage(
            new BibliographicTextSearchQuery("Ursula Le Guin"),
            [$mappedAuthor, $externalAuthor],
            null
        );
    }

    public function testExternalWorkRequiresDeclaredStrongWorkIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::author("open_library", "/authors/OL5A")
        );
    }

    public function testMalformedNamesTitlesAndProviderIdsFailClosed(): void
    {
        foreach (["", " title ", str_repeat("x", 513)] as $title) {
            try {
                new BibliographicWorkSearchResult(
                    BibliographicWorkReference::canonical(new WorkId("work-invalid")),
                    $title,
                    [],
                    [],
                    0
                );
                self::fail("Invalid Work title was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([["Open Library", "/works/OL1W"], ["open_library", " "]] as $identity) {
            try {
                BibliographicProviderEntityIdentity::work($identity[0], $identity[1]);
                self::fail("Invalid provider Work identity was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProviderCapabilitiesAreSeparateAndMayBeUnsupported(): void
    {
        $provider = new class implements BibliographicAuthorSearchProvider {
            public function key(): string { return "author_only"; }
            public function searchAuthors(
                BibliographicTextSearchQuery $query,
                ?BibliographicSearchCursor $cursor = null
            ): BibliographicAuthorSearchPage {
                return new BibliographicAuthorSearchPage($query, [], null);
            }
        };

        $capabilities = (new \ReflectionClass($provider))->getInterfaceNames();
        self::assertContains(BibliographicAuthorSearchProvider::class, $capabilities);
        self::assertNotContains(BibliographicWorkSearchProvider::class, $capabilities);
    }

    private function contract(): BibliographicTextSearchContract
    {
        return new BibliographicTextSearchContract(
            new BibliographicSearchCursorCodec(self::CURSOR_SECRET),
            new BibliographicAuthorSelectorCodec(self::SELECTOR_SECRET)
        );
    }

    /** @param array<string,mixed> $payload */
    private function signCursorPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $encoded = rtrim(strtr(base64_encode($json), "+/", "-_"), "=");
        $signature = rtrim(strtr(base64_encode(
            hash_hmac("sha256", $encoded, self::CURSOR_SECRET, true)
        ), "+/", "-_"), "=");

        return $encoded . "." . $signature;
    }
}
