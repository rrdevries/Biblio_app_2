<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicProviderEntityIdentity,
    BibliographicWorkReference,
    BibliographicWorkSelectorCodec
};
use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BibliographicWorkSelectorCodecTest extends TestCase
{
    private const string SECRET = "test-bibliographic-work-selector-secret";

    public function testCanonicalProviderAndCurrentCompositeSelectorsRoundTripExactly(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL123W"
        );
        $repository = new WorkSelectorIdentityRepository();
        $repository->map($provider, new WorkId("work-mapped"));
        $codec = new BibliographicWorkSelectorCodec(self::SECRET, $repository);
        $references = [
            "canonical" => BibliographicWorkReference::canonical(
                new WorkId("work-local")
            ),
            "provider" => BibliographicWorkReference::external($provider),
            "composite" => BibliographicWorkReference::canonical(
                new WorkId("work-mapped"),
                $provider
            ),
        ];

        foreach ($references as $form => $reference) {
            $decoded = $codec->decode($codec->encode($reference));
            self::assertSame($reference->kind(), $decoded->kind(), $form);
            self::assertSame(
                $reference->workId()?->value(),
                $decoded->workId()?->value(),
                $form
            );
            self::assertSame(
                $reference->providerIdentity()?->stableKey(),
                $decoded->providerIdentity()?->stableKey(),
                $form
            );
        }
    }

    public function testEachFormUsesOnlyItsExactPayloadFields(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL1W"
        );
        $repository = new WorkSelectorIdentityRepository();
        $repository->map($provider, new WorkId("work-a"));
        $codec = new BibliographicWorkSelectorCodec(self::SECRET, $repository);

        self::assertSame(
            ["v", "type", "form", "work_id"],
            array_keys($this->decodePayload(explode(".", $codec->encode(
                BibliographicWorkReference::canonical(new WorkId("work-a"))
            ))[0]))
        );
        self::assertSame(
            ["v", "type", "form", "provider", "provider_work_id"],
            array_keys($this->decodePayload(explode(".", $codec->encode(
                BibliographicWorkReference::external($provider)
            ))[0]))
        );
        self::assertSame(
            ["v", "type", "form", "work_id", "provider", "provider_work_id"],
            array_keys($this->decodePayload(explode(".", $codec->encode(
                BibliographicWorkReference::canonical(new WorkId("work-a"), $provider)
            ))[0]))
        );
    }

    public function testCompositeIssuanceRequiresTheCurrentExactMapping(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL2W"
        );
        $reference = BibliographicWorkReference::canonical(
            new WorkId("work-a"),
            $provider
        );

        foreach ([null, new WorkId("work-b")] as $mapped) {
            $repository = new WorkSelectorIdentityRepository();
            if ($mapped !== null) {
                $repository->map($provider, $mapped);
            }
            try {
                (new BibliographicWorkSelectorCodec(self::SECRET, $repository))
                    ->encode($reference);
                self::fail("A composite without its exact current mapping was issued.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStaleRemovedAndChangedCompositeMappingsFailClosed(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL3W"
        );
        $repository = new WorkSelectorIdentityRepository();
        $repository->map($provider, new WorkId("work-a"));
        $codec = new BibliographicWorkSelectorCodec(self::SECRET, $repository);
        $selector = $codec->encode(BibliographicWorkReference::canonical(
            new WorkId("work-a"),
            $provider
        ));

        $repository->remove($provider);
        $this->assertRejected($codec, $selector, "removed mapping");

        $repository->map($provider, new WorkId("work-b"));
        $this->assertRejected($codec, $selector, "changed mapping");
    }

    public function testCrossBindingAndAuthorityFieldTamperingFailClosed(): void
    {
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL4W"
        );
        $repository = new WorkSelectorIdentityRepository();
        $repository->map($provider, new WorkId("work-a"));
        $codec = new BibliographicWorkSelectorCodec(self::SECRET, $repository);
        $selector = $codec->encode(BibliographicWorkReference::canonical(
            new WorkId("work-a"),
            $provider
        ));
        [$payload, $signature] = explode(".", $selector);
        $decoded = $this->decodePayload($payload);

        foreach ([
            "canonical Work" => [...$decoded, "work_id" => "work-b"],
            "provider Work" => [...$decoded, "provider_work_id" => "/works/OL5W"],
            "provider" => [...$decoded, "provider" => "other_provider"],
            "form" => [...$decoded, "form" => "canonical"],
            "token type" => [...$decoded, "type" => "author_selector"],
            "version" => [...$decoded, "v" => 2],
        ] as $case => $tamperedPayload) {
            $this->assertRejected(
                $codec,
                $this->encodePayload($tamperedPayload) . "." . $signature,
                $case
            );
        }

        $reordered = [
            "type" => $decoded["type"],
            "v" => $decoded["v"],
            "form" => $decoded["form"],
            "work_id" => $decoded["work_id"],
            "provider" => $decoded["provider"],
            "provider_work_id" => $decoded["provider_work_id"],
        ];
        $this->assertRejected(
            $codec,
            $this->encodePayload($reordered) . "." . $signature,
            "re-encoded payload"
        );
    }

    #[DataProvider("invalidSignedPayloads")]
    public function testValidlySignedUnsupportedOrImpossiblePayloadFailsClosed(
        array $payload
    ): void {
        $this->expectException(ValidationException::class);
        (new BibliographicWorkSelectorCodec(
            self::SECRET,
            new WorkSelectorIdentityRepository()
        ))->decode($this->sign($payload));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSignedPayloads(): iterable
    {
        $valid = [
            "v" => 1,
            "type" => "work_selector",
            "form" => "provider",
            "provider" => "open_library",
            "provider_work_id" => "/works/OL1W",
        ];

        yield "unknown version" => [[...$valid, "v" => 9]];
        yield "unknown token type" => [[...$valid, "type" => "edition_selector"]];
        yield "unknown form" => [[...$valid, "form" => "mapped"]];
        yield "unsupported provider" => [[...$valid, "provider" => "google_books"]];
        yield "malformed provider Work ID" => [[
            ...$valid,
            "provider_work_id" => "/authors/OL1A",
        ]];
        yield "provider claims canonical ID" => [[...$valid, "work_id" => "work-a"]];
        yield "unexpected title" => [[...$valid, "title" => "Work A"]];
        yield "invalid canonical ID" => [[
            "v" => 1,
            "type" => "work_selector",
            "form" => "canonical",
            "work_id" => " ",
        ]];
        yield "canonical missing ID" => [[
            "v" => 1,
            "type" => "work_selector",
            "form" => "canonical",
        ]];
        yield "composite missing provider Work ID" => [[
            "v" => 1,
            "type" => "work_selector",
            "form" => "composite",
            "work_id" => "work-a",
            "provider" => "open_library",
        ]];
    }

    public function testMalformedUnsignedAndBadSignatureSelectorsFailClosed(): void
    {
        $canonicalPayload = [
            "v" => 1,
            "type" => "work_selector",
            "form" => "canonical",
            "work_id" => "work-a",
        ];
        $compositePayload = [
            "v" => 1,
            "type" => "work_selector",
            "form" => "composite",
            "work_id" => "work-a",
            "provider" => "open_library",
            "provider_work_id" => "/works/OL1W",
        ];
        $codec = new BibliographicWorkSelectorCodec(
            self::SECRET,
            new WorkSelectorIdentityRepository()
        );
        foreach ([
            "",
            "not-a-selector",
            json_encode($canonicalPayload, JSON_THROW_ON_ERROR),
            json_encode($compositePayload, JSON_THROW_ON_ERROR),
            $this->encodePayload($canonicalPayload) . ".invalid-signature",
        ] as $selector) {
            $this->assertRejected($codec, $selector, "malformed or unsigned token");
        }
    }

    public function testEncoderRejectsUnsupportedProviderAndEntityType(): void
    {
        $codec = new BibliographicWorkSelectorCodec(
            self::SECRET,
            new WorkSelectorIdentityRepository()
        );
        foreach ([
            BibliographicProviderEntityIdentity::work("google_books", "volume-1"),
            BibliographicProviderEntityIdentity::author(
                "open_library",
                "/authors/OL1A"
            ),
        ] as $identity) {
            try {
                $codec->encode(BibliographicWorkReference::external($identity));
                self::fail("An unsupported provider identity was encoded.");
            } catch (\InvalidArgumentException|ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function assertRejected(
        BibliographicWorkSelectorCodec $codec,
        string $selector,
        string $case
    ): void {
        try {
            $codec->decode($selector);
            self::fail("Invalid Work selector was accepted: {$case}.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
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

final class WorkSelectorIdentityRepository implements BibliographicProviderIdentityRepository
{
    /** @var array<string,WorkId> */
    private array $works = [];

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        return $this->works[$this->key($provider, $sourceType, $recordId)] ?? null;
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
        $this->works[$this->key($provider, $sourceType, $recordId)] = $workId;
    }

    public function claimEdition(
        string $provider,
        string $recordId,
        EditionId $editionId
    ): void {
    }

    public function map(
        BibliographicProviderEntityIdentity $identity,
        WorkId $workId
    ): void {
        $this->claimWork(
            $identity->providerKey(),
            "work",
            $identity->providerRecordId(),
            $workId
        );
    }

    public function remove(BibliographicProviderEntityIdentity $identity): void
    {
        unset($this->works[$this->key(
            $identity->providerKey(),
            "work",
            $identity->providerRecordId()
        )]);
    }

    private function key(string $provider, string $sourceType, string $recordId): string
    {
        return implode("\0", [$provider, $sourceType, $recordId]);
    }
}
