<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicSearchProviderAttempt
{
    public function __construct(
        private string $providerKey,
        private ProviderLookupStatus $status,
        private ?ProviderFailureReason $failureReason = null
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new ValidationException("Invalid bibliographic search provider key.");
        }
        $failed = $status !== ProviderLookupStatus::Candidates
            && $status !== ProviderLookupStatus::Miss;
        if ($failed !== ($failureReason !== null)) {
            throw new ValidationException("Invalid bibliographic provider attempt.");
        }
    }

    public function providerKey(): string { return $this->providerKey; }
    public function status(): ProviderLookupStatus { return $this->status; }
    public function failureReason(): ?ProviderFailureReason { return $this->failureReason; }
}
