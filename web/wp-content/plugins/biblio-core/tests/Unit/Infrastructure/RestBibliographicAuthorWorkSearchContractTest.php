<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorSelectorCodec,
    BibliographicAuthorWorkSearchContract,
    BibliographicAuthorWorkSearchCursor,
    BibliographicAuthorWorkSearchCursorCodec,
    BibliographicAuthorWorkSearchLane,
    BibliographicAuthorWorkSearchPage,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderAttempt,
    BibliographicWorkAuthor,
    BibliographicWorkReference,
    BibliographicWorkSearchResult
};
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Infrastructure\WordPress\Rest\{
    RestBibliographicAuthorWorkSearchContract,
    RestRequestException
};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestBibliographicAuthorWorkSearchContractTest extends TestCase
{
    private const string SELECTOR_SECRET = "rest-selected-author-selector-secret";
    private const string CURSOR_SECRET = "rest-selected-author-works-cursor-secret";

    #[DataProvider("authorReferences")]
    public function testDecoderAcceptsEveryTrustedSelectorForm(
        BibliographicAuthorReference $author
    ): void {
        $request = $this->transport()->decodeRequest([
            "author_selector" => $this->selectors()->encode($author),
            "cursor" => null,
        ]);

        self::assertSame($author->cursorContextId(), $request->author()->cursorContextId());
        self::assertNull($request->cursor());
    }

    /** @return iterable<string,array{BibliographicAuthorReference}> */
    public static function authorReferences(): iterable
    {
        $provider = BibliographicProviderEntityIdentity::author(
            "open_library",
            "/authors/OL1A"
        );
        yield "canonical" => [
            BibliographicAuthorReference::canonical(new AuthorId("author-canonical")),
        ];
        yield "provider" => [BibliographicAuthorReference::external($provider)];
        yield "composite" => [
            BibliographicAuthorReference::canonical(
                new AuthorId("author-composite"),
                $provider
            ),
        ];
    }

    #[DataProvider("invalidBodies")]
    public function testDecoderRejectsUntrustedTransport(
        array $body,
        string $code
    ): void {
        try {
            $this->transport()->decodeRequest($body);
            self::fail("Untrusted selected-Author request was accepted.");
        } catch (RestRequestException $exception) {
            self::assertSame($code, $exception->errorCode());
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidBodies(): iterable
    {
        yield "missing selector" => [[], "biblio_missing_required_field"];
        yield "selector type" => [["author_selector" => 42], "biblio_invalid_field_type"];
        yield "cursor type" => [[
            "author_selector" => "selector",
            "cursor" => false,
        ], "biblio_invalid_field_type"];
        foreach (["author_id", "provider", "library_id", "user_id", "name"] as $field) {
            yield "forbidden {$field}" => [[
                "author_selector" => "selector",
                $field => "untrusted",
            ], "biblio_unknown_request_fields"];
        }
        yield "tampered selector" => [[
            "author_selector" => "not-a-signed-selector",
        ], "biblio_invalid_field_syntax"];
    }

    #[DataProvider("invalidSignedSelectorPayloads")]
    public function testDecoderMapsSignedUnsupportedSelectorsToSafeErrors(array $payload): void
    {
        try {
            $this->transport()->decodeRequest([
                "author_selector" => $this->signSelectorPayload($payload),
            ]);
            self::fail("Unsupported signed selected-Author payload was accepted.");
        } catch (RestRequestException $exception) {
            self::assertSame("biblio_invalid_field_syntax", $exception->errorCode());
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSignedSelectorPayloads(): iterable
    {
        $valid = [
            "v" => 1,
            "type" => "author_selector",
            "form" => "provider",
            "author_id" => null,
            "provider" => "open_library",
            "provider_author_id" => "/authors/OL1A",
        ];
        yield "version" => [[...$valid, "v" => 2]];
        yield "type" => [[...$valid, "type" => "work_selector"]];
        yield "provider" => [[...$valid, "provider" => "google_books"]];
        yield "name field" => [[...$valid, "display_name" => "Untrusted Name"]];
    }

    public function testDecoderRejectsCursorIssuedForAnotherAuthor(): void
    {
        $authorA = BibliographicAuthorReference::canonical(new AuthorId("author-a"));
        $authorB = BibliographicAuthorReference::canonical(new AuthorId("author-b"));
        $cursor = $this->cursors()->encode(
            BibliographicAuthorWorkSearchCursor::forAuthor(
                $authorA,
                BibliographicAuthorWorkSearchLane::Local,
                10
            )
        );

        try {
            $this->transport()->decodeRequest([
                "author_selector" => $this->selectors()->encode($authorB),
                "cursor" => $cursor,
            ]);
            self::fail("A cursor issued for Author A was accepted for Author B.");
        } catch (RestRequestException $exception) {
            self::assertSame("biblio_invalid_field_syntax", $exception->errorCode());
        }
    }

    public function testDecoderRejectsProviderReferenceMismatchAndBadCursors(): void
    {
        $providerA = BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL1A"
            )
        );
        $providerB = BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL2A"
            )
        );
        $cursor = $this->cursors()->encode(
            BibliographicAuthorWorkSearchCursor::forAuthor(
                $providerA,
                BibliographicAuthorWorkSearchLane::External,
                10
            )
        );

        foreach ([
            [$this->selectors()->encode($providerB), $cursor],
            [$this->selectors()->encode($providerA), "not-a-cursor"],
            [$this->selectors()->encode($providerA), $cursor . "x"],
        ] as [$selector, $candidateCursor]) {
            try {
                $this->transport()->decodeRequest([
                    "author_selector" => $selector,
                    "cursor" => $candidateCursor,
                ]);
                self::fail("Provider-mismatched, malformed or tampered cursor was accepted.");
            } catch (RestRequestException $exception) {
                self::assertSame(
                    "biblio_invalid_field_syntax",
                    $exception->errorCode()
                );
            }
        }
    }

    public function testSerializerKeepsTypedWorkReferenceAndExcludesEditionFields(): void
    {
        $author = BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL1A"
            )
        );
        $work = new BibliographicWorkSearchResult(
            BibliographicWorkReference::external(
                BibliographicProviderEntityIdentity::work(
                    "open_library",
                    "/works/OL1W"
                )
            ),
            "A Provider Work",
            [new BibliographicWorkAuthor("Known Author")],
            [],
            0
        );
        $payload = $this->transport()->serialize(new BibliographicAuthorWorkSearchPage(
            $author,
            [$work],
            null,
            [new BibliographicSearchProviderAttempt(
                "open_library",
                ProviderLookupStatus::Unavailable,
                ProviderFailureReason::Timeout
            )]
        ));

        self::assertSame(["items", "next_cursor", "provider_attempts"], array_keys($payload));
        self::assertSame([
            "result_id",
            "result_kind",
            "work_id",
            "provider_identity",
            "title",
            "authors",
            "series",
        ], array_keys($payload["items"][0]));
        self::assertNull($payload["items"][0]["work_id"]);
        self::assertSame([
            "provider_key" => "open_library",
            "record_id" => "/works/OL1W",
        ], $payload["items"][0]["provider_identity"]);
        self::assertSame([
            "provider_key" => "open_library",
            "status" => "unavailable",
            "failure_reason" => "timeout",
        ], $payload["provider_attempts"][0]);
        foreach ([
            "edition_id",
            "isbn",
            "publisher",
            "publication_date",
            "language",
            "format",
            "page_count",
        ] as $field) {
            self::assertArrayNotHasKey($field, $payload["items"][0]);
        }
    }

    private function transport(): RestBibliographicAuthorWorkSearchContract
    {
        return new RestBibliographicAuthorWorkSearchContract(
            $this->selectors(),
            new BibliographicAuthorWorkSearchContract($this->cursors())
        );
    }

    private function selectors(): BibliographicAuthorSelectorCodec
    {
        return new BibliographicAuthorSelectorCodec(self::SELECTOR_SECRET);
    }

    private function cursors(): BibliographicAuthorWorkSearchCursorCodec
    {
        return new BibliographicAuthorWorkSearchCursorCodec(self::CURSOR_SECRET);
    }

    /** @param array<string,mixed> $payload */
    private function signSelectorPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $encoded = rtrim(strtr(base64_encode($json), "+/", "-_"), "=");
        $signature = hash_hmac("sha256", $encoded, self::SELECTOR_SECRET, true);
        return $encoded . "." . rtrim(strtr(base64_encode($signature), "+/", "-_"), "=");
    }
}
