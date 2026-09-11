<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use RuntimeException;

final class BibliographicSearchProviderFailure extends RuntimeException
{
    public function __construct(
        private readonly ProviderLookupStatus $status,
        private readonly ProviderFailureReason $reason
    ) {
        if ($status === ProviderLookupStatus::Candidates || $status === ProviderLookupStatus::Miss) {
            throw new \InvalidArgumentException("Invalid bibliographic provider failure status.");
        }
        parent::__construct("Bibliographic search provider failed.");
    }

    public function status(): ProviderLookupStatus { return $this->status; }
    public function reason(): ProviderFailureReason { return $this->reason; }
}
