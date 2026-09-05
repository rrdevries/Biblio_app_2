<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use LogicException;

final readonly class FirstSufficientMetadataLookupResult
{
    /**
     * @param list<ClassifiedMetadataCandidate> $candidates
     * @param list<MetadataProviderAttempt> $attempts
     */
    public function __construct(
        private MetadataLookupStatus $status,
        private array $candidates,
        private array $attempts
    ) {
        $hasCandidates = $candidates !== [];
        if (
            ($status === MetadataLookupStatus::Candidates
                || $status === MetadataLookupStatus::Ambiguous) !== $hasCandidates
        ) {
            throw new LogicException("Metadata lookup status and candidates disagree.");
        }
        if ($attempts === []) {
            throw new LogicException("Metadata lookup must retain provider attempts.");
        }
    }

    public function status(): MetadataLookupStatus { return $this->status; }

    /** @return list<ClassifiedMetadataCandidate> */
    public function candidates(): array { return $this->candidates; }

    /** @return list<MetadataProviderAttempt> */
    public function attempts(): array { return $this->attempts; }

    public function isSuccessful(): bool
    {
        return $this->status === MetadataLookupStatus::Candidates
            || $this->status === MetadataLookupStatus::Ambiguous;
    }

    public function hasSufficientCandidate(): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->quality() === CandidateQuality::Sufficient) {
                return true;
            }
        }

        return false;
    }
}
