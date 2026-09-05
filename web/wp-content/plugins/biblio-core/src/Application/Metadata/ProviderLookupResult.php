<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use LogicException;

final readonly class ProviderLookupResult
{
    /** @param list<MetadataCandidate> $candidates */
    private function __construct(
        private ProviderLookupStatus $status,
        private array $candidates,
        private ?ProviderFailureReason $failureReason
    ) {
    }

    /** @param list<MetadataCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        if ($candidates === []) {
            throw new LogicException("A candidates result cannot be empty.");
        }

        return new self(ProviderLookupStatus::Candidates, $candidates, null);
    }

    public static function miss(): self
    {
        return new self(ProviderLookupStatus::Miss, [], null);
    }

    public static function unavailable(ProviderFailureReason $reason): self
    {
        return new self(ProviderLookupStatus::Unavailable, [], $reason);
    }

    public static function rateLimited(): self
    {
        return new self(
            ProviderLookupStatus::RateLimited,
            [],
            ProviderFailureReason::RateLimited
        );
    }

    public static function configurationError(): self
    {
        return new self(
            ProviderLookupStatus::ConfigurationError,
            [],
            ProviderFailureReason::Configuration
        );
    }

    public static function invalidResponse(ProviderFailureReason $reason): self
    {
        return new self(ProviderLookupStatus::InvalidResponse, [], $reason);
    }

    public function status(): ProviderLookupStatus { return $this->status; }

    /** @return list<MetadataCandidate> */
    public function candidatesList(): array { return $this->candidates; }

    public function failureReason(): ?ProviderFailureReason
    {
        return $this->failureReason;
    }
}
