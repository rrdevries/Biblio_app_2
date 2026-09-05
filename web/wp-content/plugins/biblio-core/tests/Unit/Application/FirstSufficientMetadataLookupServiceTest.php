<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\CandidateClassifier;
use Biblio\Core\Application\Metadata\CandidateQuality;
use Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupService;
use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Application\Metadata\MetadataLookupStatus;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn13;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FirstSufficientMetadataLookupServiceTest extends TestCase
{
    public function testCandidateQualityIsProviderNeutralAndCoverIndependent(): void
    {
        $classifier = new CandidateClassifier();
        $isbn = $this->identity();

        self::assertSame(
            CandidateQuality::Sufficient,
            $classifier->classify($this->candidate("open_library", true), $isbn)
        );
        self::assertSame(
            CandidateQuality::Sufficient,
            $classifier->classify($this->candidate("google_books", true), $isbn)
        );
        self::assertSame(
            CandidateQuality::Incomplete,
            $classifier->classify($this->candidate("open_library", false), $isbn)
        );
        self::assertSame(
            CandidateQuality::Invalid,
            $classifier->classify(
                $this->candidate("google_books", true, title: null),
                $isbn
            )
        );
        self::assertSame(
            CandidateQuality::Invalid,
            $classifier->classify(
                $this->candidate(
                    "google_books",
                    true,
                    returnedIsbn: CanonicalIsbnIdentity::fromIsbn(
                        new Isbn13("9780975229804")
                    )
                ),
                $isbn
            )
        );
    }

    public function testEveryRequiredSufficiencyFieldIndependentlyMakesCandidateIncomplete(): void
    {
        $classifier = new CandidateClassifier();
        $isbn = $this->identity();

        foreach (["contributors", "languages", "publishers", "publication_date"] as $missing) {
            self::assertSame(
                CandidateQuality::Incomplete,
                $classifier->classify($this->candidateMissing($missing), $isbn),
                "Expected {$missing} to be required for sufficiency."
            );
        }
    }

    public function testSufficientOpenLibraryCandidateStopsBeforeGoogle(): void
    {
        $openLibrary = $this->provider(
            "open_library",
            ProviderLookupResult::candidates([$this->candidate("open_library", true)])
        );
        $google = $this->provider("google_books", ProviderLookupResult::miss());

        $result = $this->service($openLibrary, $google)->lookup($this->identity());

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->hasSufficientCandidate());
        self::assertSame(1, $openLibrary->callCount());
        self::assertSame(0, $google->callCount());
        self::assertCount(1, $result->attempts());
    }

    public function testOpenLibraryMissFallsBackAndReturnsOnlyUsableGoogleCandidate(): void
    {
        $openLibrary = $this->provider("open_library", ProviderLookupResult::miss());
        $google = $this->provider(
            "google_books",
            ProviderLookupResult::candidates([$this->candidate("google_books", true)])
        );

        $result = $this->service($openLibrary, $google)->lookup($this->identity());

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertSame(["google_books"], $this->candidateProviders($result));
        self::assertSame(["open_library", "google_books"], $this->attemptProviders($result));
        self::assertSame(1, $google->callCount());
    }

    public function testIncompleteOpenLibraryAndIncompleteGoogleAreBothPreserved(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::candidates([$this->candidate("open_library", false)]),
            ProviderLookupResult::candidates([$this->candidate("google_books", false)])
        );

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasSufficientCandidate());
        self::assertSame([CandidateQuality::Incomplete, CandidateQuality::Incomplete], $this->qualities($result));
        self::assertSame(["open_library", "google_books"], $this->candidateProviders($result));
        self::assertSame(["open_library", "google_books"], $this->attemptProviders($result));
    }

    public function testIncompleteOpenLibraryAndSufficientGoogleRemainSeparate(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::candidates([$this->candidate("open_library", false)]),
            ProviderLookupResult::candidates([$this->candidate("google_books", true)])
        );

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertSame([CandidateQuality::Incomplete, CandidateQuality::Sufficient], $this->qualities($result));
        self::assertSame(["Open Library title", "Google title"], $this->candidateTitles($result));
        self::assertTrue($result->hasSufficientCandidate());
        self::assertSame(["open_library", "google_books"], $this->attemptProviders($result));
    }

    public function testIncompleteOpenLibrarySurvivesGoogleFailure(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::candidates([$this->candidate("open_library", false)]),
            ProviderLookupResult::unavailable(ProviderFailureReason::Timeout)
        );

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasSufficientCandidate());
        self::assertSame(["open_library"], $this->candidateProviders($result));
        self::assertSame(
            ProviderFailureReason::Timeout,
            $result->attempts()[1]->result()->failureReason()
        );
    }

    public function testOpenLibraryFailureIsPreservedWhenGoogleIsUsable(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::unavailable(ProviderFailureReason::Network),
            ProviderLookupResult::candidates([$this->candidate("google_books", true)])
        );

        self::assertSame(MetadataLookupStatus::Candidates, $result->status());
        self::assertTrue($result->isSuccessful());
        self::assertSame(["google_books"], $this->candidateProviders($result));
        self::assertSame(
            ProviderFailureReason::Network,
            $result->attempts()[0]->result()->failureReason()
        );
        self::assertSame(["open_library", "google_books"], $this->attemptProviders($result));
    }

    public function testMissOrInvalidFromBothProvidersIsNormalNoUsableCandidate(): void
    {
        $invalidOpenLibrary = ProviderLookupResult::candidates([
            $this->candidate("open_library", true, title: null),
        ]);
        $invalidGoogle = ProviderLookupResult::candidates([
            $this->candidate("google_books", true, title: null),
        ]);
        $cases = [
            [ProviderLookupResult::miss(), ProviderLookupResult::miss()],
            [ProviderLookupResult::miss(), $invalidGoogle],
            [$invalidOpenLibrary, ProviderLookupResult::miss()],
            [$invalidOpenLibrary, $invalidGoogle],
            [
                ProviderLookupResult::invalidResponse(ProviderFailureReason::IsbnMismatch),
                ProviderLookupResult::invalidResponse(ProviderFailureReason::IsbnMismatch),
            ],
        ];

        foreach ($cases as [$openLibraryResult, $googleResult]) {
            $result = $this->lookup($openLibraryResult, $googleResult);

            self::assertSame(MetadataLookupStatus::NoUsableCandidate, $result->status());
            self::assertFalse($result->isSuccessful());
            self::assertSame([], $result->candidates());
            self::assertCount(2, $result->attempts());
        }
    }

    public function testAnyProviderFailureWithoutUsableCandidateFailsLookup(): void
    {
        $cases = [
            [ProviderLookupResult::miss(), ProviderLookupResult::unavailable(ProviderFailureReason::Timeout)],
            [
                ProviderLookupResult::invalidResponse(ProviderFailureReason::IsbnMismatch),
                ProviderLookupResult::unavailable(ProviderFailureReason::Timeout),
            ],
            [ProviderLookupResult::unavailable(ProviderFailureReason::Network), ProviderLookupResult::miss()],
            [
                ProviderLookupResult::unavailable(ProviderFailureReason::Network),
                ProviderLookupResult::rateLimited(),
            ],
            [
                ProviderLookupResult::invalidResponse(ProviderFailureReason::Malformed),
                ProviderLookupResult::invalidResponse(ProviderFailureReason::IsbnMismatch),
            ],
        ];

        foreach ($cases as [$openLibraryResult, $googleResult]) {
            $result = $this->lookup($openLibraryResult, $googleResult);

            self::assertSame(MetadataLookupStatus::ProviderFailure, $result->status());
            self::assertFalse($result->isSuccessful());
            self::assertCount(2, $result->attempts());
        }
    }

    public function testMultipleValidGoogleCandidatesMakeLookupAmbiguousWithoutSelection(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::miss(),
            ProviderLookupResult::candidates([
                $this->candidate("google_books", true, recordId: "google-sufficient"),
                $this->candidate("google_books", false, recordId: "google-incomplete"),
            ])
        );

        self::assertSame(MetadataLookupStatus::Ambiguous, $result->status());
        self::assertTrue($result->isSuccessful());
        self::assertSame([CandidateQuality::Sufficient, CandidateQuality::Incomplete], $this->qualities($result));
        self::assertCount(2, $result->candidates());
    }

    public function testConflictingProviderValuesAreNotMergedOrOverwritten(): void
    {
        $result = $this->lookup(
            ProviderLookupResult::candidates([
                $this->candidate("open_library", false, title: "Open title")
            ]),
            ProviderLookupResult::candidates([
                $this->candidate("google_books", true, title: "Google title")
            ])
        );

        self::assertSame(["Open title", "Google title"], $this->candidateTitles($result));
        self::assertCount(2, $result->candidates());
    }

    private function lookup(
        ProviderLookupResult $openLibraryResult,
        ProviderLookupResult $googleResult
    ): \Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupResult {
        return $this->service(
            $this->provider("open_library", $openLibraryResult),
            $this->provider("google_books", $googleResult)
        )->lookup($this->identity());
    }

    private function service(
        CountingMetadataProvider $openLibrary,
        CountingMetadataProvider $google
    ): FirstSufficientMetadataLookupService {
        return new FirstSufficientMetadataLookupService(
            new CandidateClassifier(),
            $openLibrary,
            $google
        );
    }

    private function provider(string $key, ProviderLookupResult $result): CountingMetadataProvider
    {
        return new CountingMetadataProvider($key, $result);
    }

    private function candidate(
        string $provider,
        bool $sufficient,
        ?string $title = "provider default",
        ?CanonicalIsbnIdentity $returnedIsbn = null,
        string $recordId = "record-1"
    ): MetadataCandidate {
        $providerTitle = match ($provider) {
            "open_library" => "Open Library title",
            "google_books" => "Google title",
            default => $title,
        };

        return new MetadataCandidate(
            $provider,
            $recordId,
            new DateTimeImmutable("2026-09-05T10:11:12+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $this->identity(),
            $returnedIsbn ?? $this->identity(),
            $title === "provider default" ? $providerTitle : $title,
            null,
            $sufficient ? ["Author"] : [],
            $sufficient ? ["nl"] : [],
            $sufficient ? ["Publisher"] : [],
            $sufficient ? "2026" : null,
            null,
            null,
            null
        );
    }

    private function identity(): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780306406157"));
    }

    private function candidateMissing(string $missing): MetadataCandidate
    {
        return new MetadataCandidate(
            "open_library",
            "record-missing-{$missing}",
            new DateTimeImmutable("2026-09-05T10:11:12+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $this->identity(),
            $this->identity(),
            "Title",
            null,
            $missing === "contributors" ? [] : ["Author"],
            $missing === "languages" ? [] : ["nl"],
            $missing === "publishers" ? [] : ["Publisher"],
            $missing === "publication_date" ? null : "2026",
            null,
            null,
            null
        );
    }

    /** @return list<string> */
    private function candidateProviders(
        \Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupResult $result
    ): array {
        return array_map(
            static fn ($classified): string => $classified->candidate()->providerKey(),
            $result->candidates()
        );
    }

    /** @return list<string> */
    private function attemptProviders(
        \Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupResult $result
    ): array {
        return array_map(
            static fn ($attempt): string => $attempt->providerKey(),
            $result->attempts()
        );
    }

    /** @return list<CandidateQuality> */
    private function qualities(
        \Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupResult $result
    ): array {
        return array_map(
            static fn ($classified): CandidateQuality => $classified->quality(),
            $result->candidates()
        );
    }

    /** @return list<?string> */
    private function candidateTitles(
        \Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupResult $result
    ): array {
        return array_map(
            static fn ($classified): ?string => $classified->candidate()->title(),
            $result->candidates()
        );
    }
}

final class CountingMetadataProvider implements MetadataProvider
{
    private int $callCount = 0;

    public function __construct(
        private readonly string $key,
        private readonly ProviderLookupResult $result
    ) {
    }

    public function key(): string { return $this->key; }

    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        ++$this->callCount;
        return $this->result;
    }

    public function callCount(): int { return $this->callCount; }
}
