<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use LogicException;

final readonly class BibliographicProviderDiscoveryResult
{
    /** @param list<BibliographicDiscoveryCandidate> $candidates */
    private function __construct(
        private ProviderLookupStatus $status,
        private array $candidates,
        private ?ProviderFailureReason $failureReason
    ) {
    }

    /** @param list<BibliographicDiscoveryCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        if ($candidates === []) { throw new LogicException("Candidates cannot be empty."); }
        return new self(ProviderLookupStatus::Candidates, $candidates, null);
    }

    public static function miss(): self
    {
        return new self(ProviderLookupStatus::Miss, [], null);
    }

    public static function failure(ProviderLookupStatus $status, ProviderFailureReason $reason): self
    {
        if ($status === ProviderLookupStatus::Candidates || $status === ProviderLookupStatus::Miss) {
            throw new LogicException("Invalid provider failure status.");
        }
        return new self($status, [], $reason);
    }

    public function status(): ProviderLookupStatus { return $this->status; }
    /** @return list<BibliographicDiscoveryCandidate> */ public function candidatesList(): array { return $this->candidates; }
    public function failureReason(): ?ProviderFailureReason { return $this->failureReason; }
}
