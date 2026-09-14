<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorDisambiguation,
    BibliographicAuthorReference,
    BibliographicAuthorSearchCursor,
    BibliographicAuthorSearchLane,
    BibliographicAuthorSearchPage,
    BibliographicAuthorSearchResult,
    BibliographicAuthorSelectorCodec,
    BibliographicProviderEntityIdentity,
    BibliographicSearchCursorCodec,
    BibliographicSearchGroup,
    BibliographicTextSearchContract,
    BibliographicTextSearchQuery,
    BibliographicTextSearchResult,
    BibliographicWorkAuthor,
    BibliographicWorkReference,
    BibliographicWorkSelectorCodec,
    BibliographicWorkSearchPage,
    BibliographicWorkSearchResult
};
use Biblio\Core\Catalog\{AuthorId,WorkId};
use Biblio\Core\Infrastructure\WordPress\Rest\{
    RestBibliographicTextSearchContract,
    RestRequestException
};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestBibliographicTextSearchContractTest extends TestCase
{
    private const string SECRET = "rest-bibliographic-search-test-secret";
    private const string SELECTOR_SECRET = "rest-bibliographic-author-selector-secret";

    public function testDecoderBuildsIndependentTypedCursors(): void
    {
        $query = new BibliographicTextSearchQuery("Ursula Le Guin");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-le-guin")),
            "Ursula K. Le Guin",
            0,
            $query
        );
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::canonical(new WorkId("work-earthsea")),
            "A Wizard of Earthsea",
            [new BibliographicWorkAuthor("Ursula K. Le Guin")],
            [],
            0
        );
        $codec = $this->codec();

        $decoded = $this->transport()->decodeRequest([
            "query" => "  Ursula   Le Guin ",
            "author_cursor" => $codec->encode(new BibliographicAuthorSearchCursor(
                $query,
                BibliographicAuthorSearchLane::Local,
                1
            )),
            "work_cursor" => $codec->encode($work->cursor($query)),
        ]);

        self::assertSame("Ursula Le Guin", $decoded->query()->value());
        self::assertSame(BibliographicSearchGroup::Authors, $decoded->authorCursor()?->group());
        self::assertSame(BibliographicSearchGroup::Works, $decoded->workCursor()?->group());
    }

    #[DataProvider("invalidBodies")]
    public function testDecoderMapsMalformedTransportToSafe400Errors(
        array $body,
        string $code
    ): void {
        try {
            $this->transport()->decodeRequest($body);
            self::fail("Malformed bibliographic REST request was accepted.");
        } catch (RestRequestException $exception) {
            self::assertSame($code, $exception->errorCode());
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidBodies(): iterable
    {
        yield "missing query" => [[], "biblio_missing_required_field"];
        yield "unknown provider" => [[
            "query" => "Dune",
            "provider" => "open_library",
        ], "biblio_unknown_request_fields"];
        yield "query type" => [["query" => 42], "biblio_invalid_field_type"];
        yield "cursor type" => [[
            "query" => "Dune",
            "author_cursor" => false,
        ], "biblio_invalid_field_type"];
        yield "empty query" => [["query" => " "], "biblio_invalid_field_syntax"];
        yield "valid ISBN" => [[
            "query" => "9780441172719",
        ], "biblio_invalid_field_syntax"];
        yield "malformed cursor" => [[
            "query" => "Dune",
            "work_cursor" => "not-a-signed-cursor",
        ], "biblio_invalid_field_syntax"];
    }

    public function testDecoderRejectsWrongGroupAndWrongQueryCursors(): void
    {
        $query = new BibliographicTextSearchQuery("Dune");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("author-herbert")),
            "Frank Herbert",
            0,
            $query
        );
        $cursor = $this->codec()->encode(new BibliographicAuthorSearchCursor(
            $query,
            BibliographicAuthorSearchLane::Local,
            1
        ));

        foreach ([
            ["query" => "Dune", "work_cursor" => $cursor],
            ["query" => "Children of Dune", "author_cursor" => $cursor],
        ] as $body) {
            try {
                $this->transport()->decodeRequest($body);
                self::fail("Mis-scoped bibliographic cursor was accepted.");
            } catch (RestRequestException $exception) {
                self::assertSame(
                    "biblio_invalid_field_syntax",
                    $exception->errorCode()
                );
            }
        }
    }

    public function testSerializerIsTypedGroupedAndEditionFree(): void
    {
        $query = new BibliographicTextSearchQuery("Octavia Butler");
        $author = new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::external(
                BibliographicProviderEntityIdentity::author(
                    "open_library",
                    "/authors/OL1A"
                )
            ),
            "Octavia E. Butler",
            0,
            $query,
            BibliographicAuthorDisambiguation::external("Kindred", 1947)
        );
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work(
                    "open_library",
                    "/works/OL1W"
                )
            ),
            "Kindred",
            [new BibliographicWorkAuthor("Octavia E. Butler")],
            [],
            0
        );

        $payload = $this->transport()->serialize(new BibliographicTextSearchResult(
            $query,
            new BibliographicAuthorSearchPage($query, [$author], null),
            new BibliographicWorkSearchPage($query, [$work], null)
        ));

        self::assertSame(["query", "authors", "works"], array_keys($payload));
        self::assertSame(
            ["items", "next_cursor", "provider_attempts"],
            array_keys($payload["authors"])
        );
        self::assertSame(
            ["items", "next_cursor", "provider_attempts"],
            array_keys($payload["works"])
        );
        self::assertSame("external_candidate", $payload["authors"]["items"][0]["result_kind"]);
        self::assertSame(
            [
                "result_id",
                "result_kind",
                "author_id",
                "display_name",
                "author_selector",
                "match_quality",
                "name_group_id",
                "disambiguation",
            ],
            array_keys($payload["authors"]["items"][0])
        );
        self::assertSame("broader", $payload["authors"]["items"][0]["match_quality"]);
        self::assertMatchesRegularExpression(
            '/^author-name-[0-9a-f]{64}$/D',
            $payload["authors"]["items"][0]["name_group_id"]
        );
        self::assertSame([
            "representative_work_title" => "Kindred",
            "linked_work_count" => null,
            "birth_year" => 1947,
        ], $payload["authors"]["items"][0]["disambiguation"]);
        $selectedAuthor = (new BibliographicAuthorSelectorCodec(
            self::SELECTOR_SECRET
        ))->decode($payload["authors"]["items"][0]["author_selector"]);
        self::assertSame(
            "/authors/OL1A",
            $selectedAuthor->providerIdentity()?->providerRecordId()
        );
        self::assertArrayNotHasKey("provider_record_id", $payload["authors"]["items"][0]);
        foreach (["top_work", "work_count", "identity_status"] as $field) {
            self::assertArrayNotHasKey($field, $payload["authors"]["items"][0]);
            self::assertArrayNotHasKey(
                $field,
                $payload["authors"]["items"][0]["disambiguation"]
            );
        }
        self::assertSame(
            [
                "result_id",
                "result_kind",
                "work_id",
                "work_selector",
                "title",
                "authors",
                "series",
            ],
            array_keys($payload["works"]["items"][0])
        );
        $selectedWork = (new BibliographicWorkSelectorCodec(
            self::SELECTOR_SECRET . "-work"
        ))->decode($payload["works"]["items"][0]["work_selector"]);
        self::assertSame(
            "/works/OL1W",
            $selectedWork->providerIdentity()?->providerRecordId()
        );
        foreach (["isbn", "publisher", "publication_date", "language", "library_id", "user_id"] as $field) {
            self::assertArrayNotHasKey($field, $payload["works"]["items"][0]);
        }
    }

    public function testSerializerProjectsCompleteAuthorPresentationContract(): void
    {
        $query = new BibliographicTextSearchQuery("Stephen King");
        $authors = [
            new BibliographicAuthorSearchResult(
                BibliographicAuthorReference::canonical(new AuthorId("author-king-it")),
                "Stephen King",
                0,
                $query,
                BibliographicAuthorDisambiguation::local(1, "It")
            ),
            new BibliographicAuthorSearchResult(
                BibliographicAuthorReference::canonical(new AuthorId("author-king-many")),
                "Stephen King",
                1,
                $query,
                BibliographicAuthorDisambiguation::local(4, null)
            ),
            new BibliographicAuthorSearchResult(
                BibliographicAuthorReference::external(
                    BibliographicProviderEntityIdentity::author(
                        "open_library",
                        "/authors/OL2A"
                    )
                ),
                "Stephen King",
                2,
                $query,
                BibliographicAuthorDisambiguation::external("The Green Mile", 1947)
            ),
            new BibliographicAuthorSearchResult(
                BibliographicAuthorReference::external(
                    BibliographicProviderEntityIdentity::author(
                        "open_library",
                        "/authors/OL3A"
                    )
                ),
                "Stephen King",
                3,
                $query,
                BibliographicAuthorDisambiguation::external(null, null)
            ),
        ];

        $payload = $this->transport()->serialize(new BibliographicTextSearchResult(
            $query,
            new BibliographicAuthorSearchPage($query, $authors, null),
            new BibliographicWorkSearchPage($query, [], null)
        ));
        $items = $payload["authors"]["items"];

        self::assertSame("exact", $items[0]["match_quality"]);
        self::assertSame($items[0]["name_group_id"], $items[3]["name_group_id"]);
        self::assertSame([
            "representative_work_title" => "It",
            "linked_work_count" => 1,
            "birth_year" => null,
        ], $items[0]["disambiguation"]);
        self::assertSame([
            "representative_work_title" => null,
            "linked_work_count" => 4,
            "birth_year" => null,
        ], $items[1]["disambiguation"]);
        self::assertSame([
            "representative_work_title" => "The Green Mile",
            "linked_work_count" => null,
            "birth_year" => 1947,
        ], $items[2]["disambiguation"]);
        self::assertSame([
            "representative_work_title" => null,
            "linked_work_count" => null,
            "birth_year" => null,
        ], $items[3]["disambiguation"]);
        self::assertNotSame($items[2]["result_id"], $items[3]["result_id"]);
    }

    private function codec(): BibliographicSearchCursorCodec
    {
        return new BibliographicSearchCursorCodec(self::SECRET);
    }

    private function transport(): RestBibliographicTextSearchContract
    {
        return new RestBibliographicTextSearchContract(
            new BibliographicTextSearchContract(
                $this->codec(),
                new BibliographicAuthorSelectorCodec(self::SELECTOR_SECRET),
                new BibliographicWorkSelectorCodec(self::SELECTOR_SECRET . "-work")
            )
        );
    }
}
