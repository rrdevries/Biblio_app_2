<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use InvalidArgumentException;

final readonly class FirstSufficientMetadataLookupService
{
    public function __construct(
        private CandidateClassifier $classifier,
        private MetadataProvider $primaryProvider,
        private MetadataProvider $fallbackProvider
    ) {
        if ($primaryProvider->key() === $fallbackProvider->key()) {
            throw new InvalidArgumentException("Metadata providers must be distinct.");
        }
    }

    public function lookup(CanonicalIsbnIdentity $isbn): FirstSufficientMetadataLookupResult
    {
        $primaryResult = $this->primaryProvider->lookup($isbn);
        $primaryCandidates = $this->usableCandidates($primaryResult, $isbn);
        $attempts = [
            new MetadataProviderAttempt($this->primaryProvider->key(), $primaryResult),
        ];

        if ($this->hasSufficientCandidate($primaryCandidates)) {
            return new FirstSufficientMetadataLookupResult(
                count($primaryCandidates) > 1
                    ? MetadataLookupStatus::Ambiguous
                    : MetadataLookupStatus::Candidates,
                $primaryCandidates,
                $attempts
            );
        }

        $fallbackResult = $this->fallbackProvider->lookup($isbn);
        $fallbackCandidates = $this->usableCandidates($fallbackResult, $isbn);
        $attempts[] = new MetadataProviderAttempt(
            $this->fallbackProvider->key(),
            $fallbackResult
        );
        $candidates = [...$primaryCandidates, ...$fallbackCandidates];

        if ($candidates !== []) {
            return new FirstSufficientMetadataLookupResult(
                count($fallbackCandidates) > 1
                    ? MetadataLookupStatus::Ambiguous
                    : MetadataLookupStatus::Candidates,
                $candidates,
                $attempts
            );
        }

        return new FirstSufficientMetadataLookupResult(
            $this->isTechnicalFailure($primaryResult)
                || $this->isTechnicalFailure($fallbackResult)
                    ? MetadataLookupStatus::ProviderFailure
                    : MetadataLookupStatus::NoUsableCandidate,
            [],
            $attempts
        );
    }

    /** @return list<ClassifiedMetadataCandidate> */
    private function usableCandidates(
        ProviderLookupResult $result,
        CanonicalIsbnIdentity $isbn
    ): array {
        $usable = [];
        foreach ($result->candidatesList() as $candidate) {
            $quality = $this->classifier->classify($candidate, $isbn);
            if ($quality !== CandidateQuality::Invalid) {
                $usable[] = new ClassifiedMetadataCandidate($candidate, $quality);
            }
        }

        return $usable;
    }

    /** @param list<ClassifiedMetadataCandidate> $candidates */
    private function hasSufficientCandidate(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate->quality() === CandidateQuality::Sufficient) {
                return true;
            }
        }

        return false;
    }

    private function isTechnicalFailure(ProviderLookupResult $result): bool
    {
        return match ($result->status()) {
            ProviderLookupStatus::Unavailable,
            ProviderLookupStatus::RateLimited,
            ProviderLookupStatus::ConfigurationError => true,
            ProviderLookupStatus::InvalidResponse =>
                $result->failureReason() !== ProviderFailureReason::IsbnMismatch,
            ProviderLookupStatus::Candidates,
            ProviderLookupStatus::Miss => false,
        };
    }
}
