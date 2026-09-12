<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicEditionReference,
    BibliographicEditionSearchContract,
    BibliographicEditionSearchCursor,
    BibliographicEditionSearchCursorCodec,
    BibliographicEditionSearchLane,
    BibliographicEditionSearchPage,
    BibliographicEditionSearchResult,
    BibliographicProviderEntityIdentity,
    BibliographicSearchProviderAttempt,
    BibliographicWorkReference,
    BibliographicWorkSelectorCodec
};
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\WordPress\Rest\{
    RestBibliographicWorkEditionSearchContract,
    RestRequestException
};
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestBibliographicWorkEditionSearchContractTest extends TestCase
{
    private const string SELECTOR_SECRET = "rest-selected-work-selector-secret";
    private const string CURSOR_SECRET = "rest-selected-work-editions-cursor-secret";

    #[DataProvider("workReferences")]
    public function testDecoderAcceptsEveryTrustedSelectorForm(
        BibliographicWorkReference $work,
        RestWorkIdentityRepository $identities
    ): void {
        $request = $this->transport($identities)->decodeRequest([
            "work_selector" => $this->selectors($identities)->encode($work),
            "cursor" => null,
        ]);

        self::assertSame($work->cursorContextId(), $request->work()->cursorContextId());
        self::assertNull($request->cursor());
    }

    /** @return iterable<string,array{BibliographicWorkReference,RestWorkIdentityRepository}> */
    public static function workReferences(): iterable
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL1W"
        );
        yield "canonical" => [
            BibliographicWorkReference::canonical(new WorkId("work-canonical")),
            new RestWorkIdentityRepository(),
        ];
        yield "provider" => [
            BibliographicWorkReference::external($provider),
            new RestWorkIdentityRepository(),
        ];
        yield "composite" => [
            BibliographicWorkReference::canonical(
                new WorkId("work-composite"),
                $provider
            ),
            new RestWorkIdentityRepository([
                "open_library\0work\0/works/OL1W" => "work-composite",
            ]),
        ];
    }

    #[DataProvider("invalidBodies")]
    public function testDecoderRejectsUntrustedTransport(array $body, string $code): void
    {
        try {
            $this->transport()->decodeRequest($body);
            self::fail("Untrusted selected-Work request was accepted.");
        } catch (RestRequestException $exception) {
            self::assertSame($code, $exception->errorCode());
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidBodies(): iterable
    {
        yield "missing selector" => [[], "biblio_missing_required_field"];
        yield "selector type" => [["work_selector" => 42], "biblio_invalid_field_type"];
        yield "cursor type" => [[
            "work_selector" => "selector",
            "cursor" => false,
        ], "biblio_invalid_field_type"];
        foreach ([
            "work_id",
            "provider",
            "provider_work_id",
            "result_id",
            "title",
            "isbn",
            "author_id",
            "library_id",
            "user_id",
        ] as $field) {
            yield "forbidden {$field}" => [[
                "work_selector" => "selector",
                $field => "untrusted",
            ], "biblio_unknown_request_fields"];
        }
        yield "tampered selector" => [[
            "work_selector" => "not-a-signed-selector",
        ], "biblio_invalid_field_syntax"];
    }

    #[DataProvider("invalidSignedSelectorPayloads")]
    public function testDecoderMapsSignedUnsupportedSelectorsToSafeErrors(array $payload): void
    {
        try {
            $this->transport()->decodeRequest([
                "work_selector" => $this->signSelectorPayload($payload),
            ]);
            self::fail("Unsupported signed selected-Work payload was accepted.");
        } catch (RestRequestException $exception) {
            self::assertSame("biblio_invalid_field_syntax", $exception->errorCode());
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSignedSelectorPayloads(): iterable
    {
        $valid = [
            "v" => 1,
            "type" => "work_selector",
            "form" => "provider",
            "provider" => "open_library",
            "provider_work_id" => "/works/OL1W",
        ];
        yield "version" => [[...$valid, "v" => 2]];
        yield "type" => [[...$valid, "type" => "author_selector"]];
        yield "provider" => [[...$valid, "provider" => "google_books"]];
        yield "title field" => [[...$valid, "title" => "Untrusted title"]];
    }

    public function testDecoderRejectsStaleCompositeBeforeReturningARequest(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL7W"
        );
        $current = new RestWorkIdentityRepository([
            "open_library\0work\0/works/OL7W" => "work-a",
        ]);
        $selector = $this->selectors($current)->encode(
            BibliographicWorkReference::canonical(new WorkId("work-a"), $provider)
        );

        foreach ([
            new RestWorkIdentityRepository(),
            new RestWorkIdentityRepository([
                "open_library\0work\0/works/OL7W" => "work-b",
            ]),
        ] as $stale) {
            try {
                $this->transport($stale)->decodeRequest(["work_selector" => $selector]);
                self::fail("A stale composite Work selector was accepted.");
            } catch (RestRequestException $exception) {
                self::assertSame("biblio_invalid_field_syntax", $exception->errorCode());
            }
        }
    }

    public function testDecoderRejectsDifferentWorkProviderMismatchAndBadCursors(): void
    {
        $workA = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $workB = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL2W")
        );
        $cursor = $this->cursors()->encode(BibliographicEditionSearchCursor::forWork(
            $workA,
            BibliographicEditionSearchLane::External,
            10
        ));

        foreach ([
            [$this->selectors()->encode($workB), $cursor],
            [$this->selectors()->encode($workA), "not-a-cursor"],
            [$this->selectors()->encode($workA), $cursor . "x"],
        ] as [$selector, $candidateCursor]) {
            try {
                $this->transport()->decodeRequest([
                    "work_selector" => $selector,
                    "cursor" => $candidateCursor,
                ]);
                self::fail("Cross-bound, malformed or tampered cursor was accepted.");
            } catch (RestRequestException $exception) {
                self::assertSame("biblio_invalid_field_syntax", $exception->errorCode());
            }
        }
    }

    public function testSerializerUsesOnlyTheTypedEditionPage(): void
    {
        $work = BibliographicWorkReference::external(
            BibliographicProviderEntityIdentity::work("open_library", "/works/OL1W")
        );
        $edition = new BibliographicEditionSearchResult(
            BibliographicEditionReference::external(
                BibliographicProviderEntityIdentity::edition(
                    "open_library",
                    "/books/OL1M"
                )
            ),
            $work,
            "Concrete ISBN-less Edition",
            null,
            "Subtitle",
            ["Known Contributor"],
            ["eng"],
            ["Known Publisher"],
            "2026",
            320,
            "Hardcover",
            0,
            new DateTimeImmutable("2026-09-12T10:00:00Z"),
            MetadataMatchMethod::TextSearch,
            $work->providerIdentity()
        );
        $payload = $this->transport()->serialize(new BibliographicEditionSearchPage(
            $work,
            [$edition],
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
            "edition_id",
            "provider_identity",
            "provider_work_identity",
            "parent_work_result_id",
            "title",
            "subtitle",
            "contributors",
            "languages",
            "publishers",
            "publication_date",
            "isbn_10",
            "isbn_13",
            "format",
            "page_count",
            "presentation_order",
            "requires_materialization",
            "can_add_work_only",
            "can_add_edition_specific",
        ], array_keys($payload["items"][0]));
        self::assertNull($payload["items"][0]["edition_id"]);
        self::assertNull($payload["items"][0]["isbn_13"]);
        self::assertSame("/books/OL1M", $payload["items"][0]["provider_identity"]["record_id"]);
        self::assertNull($payload["next_cursor"]);
        self::assertSame("unavailable", $payload["provider_attempts"][0]["status"]);
        foreach (["item_id", "library_id", "user_id", "selector_payload"] as $field) {
            self::assertArrayNotHasKey($field, $payload["items"][0]);
        }
    }

    private function transport(
        ?RestWorkIdentityRepository $identities = null
    ): RestBibliographicWorkEditionSearchContract {
        $identities ??= new RestWorkIdentityRepository();
        return new RestBibliographicWorkEditionSearchContract(
            $this->selectors($identities),
            new BibliographicEditionSearchContract($this->cursors())
        );
    }

    private function selectors(
        ?RestWorkIdentityRepository $identities = null
    ): BibliographicWorkSelectorCodec {
        return new BibliographicWorkSelectorCodec(
            self::SELECTOR_SECRET,
            $identities ?? new RestWorkIdentityRepository()
        );
    }

    private function cursors(): BibliographicEditionSearchCursorCodec
    {
        return new BibliographicEditionSearchCursorCodec(self::CURSOR_SECRET);
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

final class RestWorkIdentityRepository implements BibliographicProviderIdentityRepository
{
    /** @param array<string,string> $workMappings */
    public function __construct(private array $workMappings = []) {}

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        $workId = $this->workMappings[implode("\0", [$provider, $sourceType, $recordId])]
            ?? null;
        return $workId === null ? null : new WorkId($workId);
    }

    public function findEdition(string $provider, string $recordId): ?EditionId
    {
        return null;
    }

    public function claimWork(
        string $provider,
        string $sourceType,
        string $recordId,
        WorkId $workId
    ): void {
        $this->workMappings[implode("\0", [$provider, $sourceType, $recordId])]
            = $workId->value();
    }

    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void
    {
    }
}
