<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataWorkRelation;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryConfiguration;
use Biblio\Core\Infrastructure\Metadata\OpenLibrary\OpenLibraryMetadataProvider;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenLibraryMetadataProviderTest extends TestCase
{
    public function testExactFixtureMapsToProviderNeutralCandidate(): void
    {
        [$provider, $http] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("exact.json"))
            )
        );

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertNull($result->failureReason());
        self::assertCount(1, $result->candidatesList());
        $candidate = $result->candidatesList()[0];
        self::assertSame("open_library", $candidate->providerKey());
        self::assertSame("/books/OL7353617M", $candidate->providerRecordId());
        self::assertSame("2026-09-05T10:11:12+00:00", $candidate->retrievedAt()->format(DATE_ATOM));
        self::assertSame(MetadataMatchMethod::ExactIsbn, $candidate->matchMethod());
        self::assertSame("9780306406157", $candidate->queriedIsbn()->isbn13()->value());
        self::assertSame("9780306406157", $candidate->returnedIsbn()->isbn13()->value());
        self::assertSame("0306406152", $candidate->returnedIsbn()->isbn10()?->value());
        self::assertSame("Gravitation", $candidate->title());
        self::assertSame("Foundations and Frontiers", $candidate->subtitle());
        self::assertSame(["Charles W. Misner", "Kip S. Thorne"], $candidate->contributors());
        self::assertSame(["eng"], $candidate->languages());
        self::assertSame(["Example Academic Press"], $candidate->publishers());
        self::assertSame("1973", $candidate->publicationDate());
        self::assertSame(1279, $candidate->pageCount());
        self::assertSame("Hardcover", $candidate->format());
        self::assertSame("/works/OL12345W", $candidate->workLink()?->providerWorkKey());
        self::assertSame(MetadataWorkRelation::ExplicitLink, $candidate->workLink()?->relation());

        $request = $http->request();
        self::assertSame(
            "https://openlibrary.org/api/books?bibkeys=ISBN%3A9780306406157&jscmd=details&format=json",
            $request->url()
        );
        self::assertSame(4.0, $request->timeoutSeconds());
        self::assertSame(262144, $request->maximumResponseBytes());
        self::assertSame("application/json", $request->headers()["Accept"]);
        self::assertSame(
            "Biblio/2.001 (mailto:metadata@example.test)",
            $request->headers()["User-Agent"]
        );
    }

    public function testCanonicalIsbn10InputUsesSameIsbn13ProviderQuery(): void
    {
        [$provider, $http] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("miss.json"))
            )
        );

        $provider->lookup(CanonicalIsbnIdentity::fromIsbn(new Isbn10("0-306-40615-2")));

        self::assertStringContainsString(
            "bibkeys=ISBN%3A9780306406157",
            $http->request()->url()
        );
    }

    public function testNoResultIsNormalMiss(): void
    {
        [$provider] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("miss.json"))
            )
        );

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::Miss, $result->status());
        self::assertSame([], $result->candidatesList());
        self::assertNull($result->failureReason());
    }

    public function testIncompleteMetadataDoesNotInventValues(): void
    {
        [$provider] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("incomplete.json"))
            )
        );

        $result = $provider->lookup($this->identity());
        $candidate = $result->candidatesList()[0];

        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertSame("Gravitation", $candidate->title());
        self::assertNull($candidate->subtitle());
        self::assertSame([], $candidate->contributors());
        self::assertSame([], $candidate->languages());
        self::assertSame([], $candidate->publishers());
        self::assertNull($candidate->publicationDate());
        self::assertNull($candidate->pageCount());
        self::assertNull($candidate->format());
        self::assertNull($candidate->workLink());
    }

    public function testIsbnMismatchIsRejected(): void
    {
        [$provider] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("isbn-mismatch.json"))
            )
        );

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame(ProviderFailureReason::IsbnMismatch, $result->failureReason());
        self::assertSame([], $result->candidatesList());
    }

    public function testMalformedPayloadIsRejectedWithoutPayloadLeakage(): void
    {
        [$provider] = $this->provider(
            ProviderHttpResult::response(
                new ProviderHttpResponse(200, $this->fixture("malformed.txt"))
            )
        );

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame(ProviderFailureReason::Malformed, $result->failureReason());
        self::assertSame([], $result->candidatesList());
    }

    #[DataProvider("providerFailureCases")]
    public function testProviderFailuresAreControlled(
        ProviderHttpResult $httpResult,
        ProviderLookupStatus $expectedStatus,
        ProviderFailureReason $expectedReason
    ): void {
        [$provider] = $this->provider($httpResult);

        $result = $provider->lookup($this->identity());

        self::assertSame($expectedStatus, $result->status());
        self::assertSame($expectedReason, $result->failureReason());
        self::assertSame([], $result->candidatesList());
    }

    /** @return iterable<string, array{ProviderHttpResult, ProviderLookupStatus, ProviderFailureReason}> */
    public static function providerFailureCases(): iterable
    {
        yield "HTTP 429" => [
            ProviderHttpResult::response(
                new ProviderHttpResponse(
                    429,
                    self::fixtureStatic("rate-limited.json")
                )
            ),
            ProviderLookupStatus::RateLimited,
            ProviderFailureReason::RateLimited,
        ];
        yield "HTTP 503" => [
            ProviderHttpResult::response(
                new ProviderHttpResponse(
                    503,
                    self::fixtureStatic("server-error.json")
                )
            ),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Http5xx,
        ];
        yield "timeout" => [
            ProviderHttpResult::timeout(),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Timeout,
        ];
        yield "network" => [
            ProviderHttpResult::networkFailure(),
            ProviderLookupStatus::Unavailable,
            ProviderFailureReason::Network,
        ];
    }

    public function testConfigurationRequiresExplicitValidContact(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OpenLibraryConfiguration("Biblio", "2.001", "");
    }

    public function testNeutralResultContractIncludesConfigurationFailure(): void
    {
        $result = ProviderLookupResult::configurationError();

        self::assertSame(ProviderLookupStatus::ConfigurationError, $result->status());
        self::assertSame(ProviderFailureReason::Configuration, $result->failureReason());
        self::assertSame([], $result->candidatesList());
    }

    /** @return array{OpenLibraryMetadataProvider, FixtureProviderHttpClient} */
    private function provider(ProviderHttpResult $result): array
    {
        $http = new FixtureProviderHttpClient($result);

        return [
            new OpenLibraryMetadataProvider(
                $http,
                new FixedMetadataClock(
                    new DateTimeImmutable("2026-09-05T10:11:12+00:00")
                ),
                new IsbnCanonicalizer(),
                new OpenLibraryConfiguration(
                    "Biblio",
                    "2.001",
                    "metadata@example.test"
                )
            ),
            $http,
        ];
    }

    private function identity(): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780306406157"));
    }

    private function fixture(string $name): string
    {
        return self::fixtureStatic($name);
    }

    private static function fixtureStatic(string $name): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . "/Fixtures/OpenLibrary/{$name}"
        );
        self::assertIsString($contents);

        return $contents;
    }
}

final class FixtureProviderHttpClient implements ProviderHttpClient
{
    private ?ProviderHttpRequest $request = null;

    public function __construct(private readonly ProviderHttpResult $result) {}

    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->request = $request;
        return $this->result;
    }

    public function request(): ProviderHttpRequest
    {
        self::assertRequestCaptured($this->request);
        return $this->request;
    }

    private static function assertRequestCaptured(?ProviderHttpRequest $request): void
    {
        if ($request === null) {
            throw new \LogicException("No provider request was captured.");
        }
    }
}

final readonly class FixedMetadataClock implements MetadataClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}
