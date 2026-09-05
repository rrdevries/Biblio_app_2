<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\CandidateClassifier;
use Biblio\Core\Application\Metadata\CandidateQuality;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksConfiguration;
use Biblio\Core\Infrastructure\Metadata\GoogleBooks\GoogleBooksMetadataProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleBooksMetadataProviderTest extends TestCase
{
    public function testExactFixtureMapsToProviderNeutralSufficientCandidate(): void
    {
        [$provider, $http] = $this->provider($this->response("exact.json"));

        $result = $provider->lookup($this->identity());

        self::assertSame("google_books", $provider->key());
        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertCount(1, $result->candidatesList());
        $candidate = $result->candidatesList()[0];
        self::assertSame("google_books", $candidate->providerKey());
        self::assertSame("google-volume-1", $candidate->providerRecordId());
        self::assertSame("2026-09-05T10:11:12+00:00", $candidate->retrievedAt()->format(DATE_ATOM));
        self::assertSame(MetadataMatchMethod::ExactIsbn, $candidate->matchMethod());
        self::assertSame("9780306406157", $candidate->queriedIsbn()->isbn13()->value());
        self::assertSame("9780306406157", $candidate->returnedIsbn()->isbn13()->value());
        self::assertSame("0306406152", $candidate->returnedIsbn()->isbn10()?->value());
        self::assertSame("Gravitation", $candidate->title());
        self::assertSame("Foundations and Frontiers", $candidate->subtitle());
        self::assertSame(["Charles W. Misner", "Kip S. Thorne"], $candidate->contributors());
        self::assertSame(["en"], $candidate->languages());
        self::assertSame(["Example Academic Press"], $candidate->publishers());
        self::assertSame("1973", $candidate->publicationDate());
        self::assertSame(1279, $candidate->pageCount());
        self::assertNull($candidate->format());
        self::assertNull($candidate->workLink());
        self::assertSame(
            CandidateQuality::Sufficient,
            (new CandidateClassifier())->classify($candidate, $this->identity())
        );

        $request = $http->request();
        self::assertSame(
            "https://www.googleapis.com/books/v1/volumes?q=isbn%3A9780306406157&maxResults=10&key=test-api-key",
            $request->url()
        );
        self::assertSame(["Accept" => "application/json"], $request->headers());
        self::assertSame(4.0, $request->timeoutSeconds());
        self::assertSame(262144, $request->maximumResponseBytes());
    }

    public function testIncompleteFixtureDoesNotInventValues(): void
    {
        [$provider] = $this->provider($this->response("incomplete.json"));

        $candidate = $provider->lookup($this->identity())->candidatesList()[0];

        self::assertSame("Gravitation", $candidate->title());
        self::assertSame([], $candidate->contributors());
        self::assertSame([], $candidate->languages());
        self::assertSame([], $candidate->publishers());
        self::assertNull($candidate->publicationDate());
        self::assertNull($candidate->subtitle());
        self::assertNull($candidate->pageCount());
        self::assertSame(
            CandidateQuality::Incomplete,
            (new CandidateClassifier())->classify($candidate, $this->identity())
        );
    }

    public function testMissIsNormal(): void
    {
        [$provider] = $this->provider($this->response("miss.json"));

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::Miss, $result->status());
        self::assertSame([], $result->candidatesList());
        self::assertNull($result->failureReason());
    }

    public function testIsbnMismatchIsRejected(): void
    {
        [$provider] = $this->provider($this->response("isbn-mismatch.json"));

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame(ProviderFailureReason::IsbnMismatch, $result->failureReason());
        self::assertSame([], $result->candidatesList());
    }

    public function testMalformedResponseIsControlled(): void
    {
        [$provider] = $this->provider($this->response("malformed.txt"));

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::InvalidResponse, $result->status());
        self::assertSame(ProviderFailureReason::Malformed, $result->failureReason());
    }

    public function testMultipleExactVolumesAreAllPreservedWithoutRanking(): void
    {
        [$provider] = $this->provider($this->response("multiple-valid.json"));

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::Candidates, $result->status());
        self::assertSame(
            ["google-volume-complete", "google-volume-partial"],
            array_map(
                static fn ($candidate): string => $candidate->providerRecordId(),
                $result->candidatesList()
            )
        );
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
                new ProviderHttpResponse(429, self::fixtureStatic("rate-limited.json"))
            ),
            ProviderLookupStatus::RateLimited,
            ProviderFailureReason::RateLimited,
        ];
        yield "HTTP 503" => [
            ProviderHttpResult::response(
                new ProviderHttpResponse(503, self::fixtureStatic("server-error.json"))
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

    public function testMissingApiKeyDisablesOnlyThisProviderWithoutHttpCall(): void
    {
        $http = new GoogleFixtureProviderHttpClient($this->response("exact.json"));
        $provider = new GoogleBooksMetadataProvider(
            $http,
            new GoogleFixedMetadataClock(new DateTimeImmutable("2026-09-05T10:11:12+00:00")),
            new IsbnCanonicalizer(),
            new GoogleBooksConfiguration(null)
        );

        $result = $provider->lookup($this->identity());

        self::assertSame(ProviderLookupStatus::ConfigurationError, $result->status());
        self::assertSame(0, $http->callCount());
    }

    /** @return array{GoogleBooksMetadataProvider, GoogleFixtureProviderHttpClient} */
    private function provider(ProviderHttpResult $result): array
    {
        $http = new GoogleFixtureProviderHttpClient($result);

        return [
            new GoogleBooksMetadataProvider(
                $http,
                new GoogleFixedMetadataClock(
                    new DateTimeImmutable("2026-09-05T10:11:12+00:00")
                ),
                new IsbnCanonicalizer(),
                new GoogleBooksConfiguration("test-api-key")
            ),
            $http,
        ];
    }

    private function response(string $fixture): ProviderHttpResult
    {
        return ProviderHttpResult::response(
            new ProviderHttpResponse(200, self::fixtureStatic($fixture))
        );
    }

    private function identity(): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780306406157"));
    }

    private static function fixtureStatic(string $name): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . "/Fixtures/GoogleBooks/{$name}"
        );
        self::assertIsString($contents);

        return $contents;
    }
}

final class GoogleFixtureProviderHttpClient implements ProviderHttpClient
{
    private ?ProviderHttpRequest $request = null;
    private int $callCount = 0;

    public function __construct(private readonly ProviderHttpResult $result) {}

    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $this->request = $request;
        ++$this->callCount;
        return $this->result;
    }

    public function request(): ProviderHttpRequest
    {
        if ($this->request === null) {
            throw new \LogicException("No provider request was captured.");
        }
        return $this->request;
    }

    public function callCount(): int { return $this->callCount; }
}

final readonly class GoogleFixedMetadataClock implements MetadataClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}
