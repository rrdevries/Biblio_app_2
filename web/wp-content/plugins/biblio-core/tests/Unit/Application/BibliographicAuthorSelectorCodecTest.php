<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorSelectorCodec,
    BibliographicProviderEntityIdentity
};
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BibliographicAuthorSelectorCodecTest extends TestCase
{
    private const string SECRET = "test-bibliographic-author-selector-secret";

    public function testCanonicalProviderAndTrustedCompositeSelectorsRoundTripExactly(): void
    {
        $provider = BibliographicProviderEntityIdentity::author(
            "open_library",
            "/authors/OL123A"
        );
        $references = [
            "canonical" => BibliographicAuthorReference::canonical(
                new AuthorId("author-local")
            ),
            "provider" => BibliographicAuthorReference::external($provider),
            "composite" => BibliographicAuthorReference::canonical(
                new AuthorId("author-mapped"),
                $provider
            ),
        ];

        foreach ($references as $form => $reference) {
            $decoded = $this->codec()->decode($this->codec()->encode($reference));
            self::assertSame($reference->kind(), $decoded->kind(), $form);
            self::assertSame(
                $reference->authorId()?->value(),
                $decoded->authorId()?->value(),
                $form
            );
            self::assertSame(
                $reference->providerIdentity()?->stableKey(),
                $decoded->providerIdentity()?->stableKey(),
                $form
            );
        }
    }

    public function testCrossBindingAndAuthorityFieldTamperingFailClosed(): void
    {
        $reference = BibliographicAuthorReference::canonical(
            new AuthorId("author-a"),
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL1A"
            )
        );
        $selector = $this->codec()->encode($reference);
        [$payload, $signature] = explode(".", $selector);
        $decoded = $this->decodePayload($payload);

        foreach ([
            "canonical Author" => [...$decoded, "author_id" => "author-b"],
            "provider Author" => [...$decoded, "provider_author_id" => "/authors/OL2A"],
            "provider" => [...$decoded, "provider" => "other_provider"],
            "form" => [...$decoded, "form" => "canonical"],
            "token type" => [...$decoded, "type" => "work_selector"],
            "version" => [...$decoded, "v" => 2],
        ] as $case => $tamperedPayload) {
            try {
                $this->codec()->decode(
                    $this->encodePayload($tamperedPayload) . "." . $signature
                );
                self::fail("Tampered {$case} was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider("invalidSignedPayloads")]
    public function testValidlySignedUnsupportedOrImpossiblePayloadFailsClosed(
        array $payload
    ): void {
        $this->expectException(ValidationException::class);
        $this->codec()->decode($this->sign($payload));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSignedPayloads(): iterable
    {
        $valid = [
            "v" => 1,
            "type" => "author_selector",
            "form" => "composite",
            "author_id" => "author-a",
            "provider" => "open_library",
            "provider_author_id" => "/authors/OL1A",
        ];

        yield "unknown version" => [[...$valid, "v" => 9]];
        yield "unknown token type" => [[...$valid, "type" => "edition_selector"]];
        yield "unknown form" => [[...$valid, "form" => "mapped"]];
        yield "unsupported provider" => [[...$valid, "provider" => "google_books"]];
        yield "malformed provider Author ID" => [[
            ...$valid,
            "provider_author_id" => "/works/OL1W",
        ]];
        yield "malformed canonical ID" => [[...$valid, "author_id" => " "]];
        yield "canonical claims provider lane" => [[...$valid, "form" => "canonical"]];
        yield "provider claims canonical ID" => [[...$valid, "form" => "provider"]];
        yield "composite missing canonical ID" => [[...$valid, "author_id" => null]];
        yield "unexpected field" => [[...$valid, "display_name" => "Author A"]];
    }

    public function testMalformedUnsignedAndBadSignatureSelectorsFailClosed(): void
    {
        $payload = [
            "v" => 1,
            "type" => "author_selector",
            "form" => "canonical",
            "author_id" => "author-a",
            "provider" => null,
            "provider_author_id" => null,
        ];
        foreach ([
            "",
            "not-a-selector",
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->encodePayload($payload) . ".invalid-signature",
        ] as $selector) {
            try {
                $this->codec()->decode($selector);
                self::fail("Malformed or unsigned Author selector was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEncoderRejectsUnsupportedProviderIdentity(): void
    {
        $this->expectException(ValidationException::class);
        $this->codec()->encode(BibliographicAuthorReference::external(
            BibliographicProviderEntityIdentity::author(
                "google_books",
                "volume-author-1"
            )
        ));
    }

    private function codec(): BibliographicAuthorSelectorCodec
    {
        return new BibliographicAuthorSelectorCodec(self::SECRET);
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload): string
    {
        $encoded = $this->encodePayload($payload);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac("sha256", $encoded, self::SECRET, true)
        ), "+/", "-_"), "=");
        return $encoded . "." . $signature;
    }

    /** @param array<string,mixed> $payload */
    private function encodePayload(array $payload): string
    {
        return rtrim(strtr(base64_encode(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        ), "+/", "-_"), "=");
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $payload): array
    {
        $padding = (4 - strlen($payload) % 4) % 4;
        $decoded = base64_decode(
            strtr($payload, "-_", "+/") . str_repeat("=", $padding),
            true
        );
        self::assertIsString($decoded);
        $value = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }
}
