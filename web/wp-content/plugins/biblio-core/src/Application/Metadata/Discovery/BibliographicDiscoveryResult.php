<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\MetadataLookupId;

final readonly class BibliographicDiscoveryResult
{
    /**
     * @param list<BibliographicDiscoveryCandidate> $candidates
     * @param list<array{provider_key:string,status:string,failure_reason:?string}> $attempts
     */
    public function __construct(
        private BibliographicDiscoveryQuery $query,
        private BibliographicDiscoveryStatus $status,
        private array $candidates,
        private ?MetadataLookupId $discoveryId,
        private array $attempts = []
    ) {
    }

    public function query(): BibliographicDiscoveryQuery { return $this->query; }
    public function status(): BibliographicDiscoveryStatus { return $this->status; }
    /** @return list<BibliographicDiscoveryCandidate> */ public function candidates(): array { return $this->candidates; }
    public function discoveryId(): ?MetadataLookupId { return $this->discoveryId; }
    /** @return list<array{provider_key:string,status:string,failure_reason:?string}> */ public function attempts(): array { return $this->attempts; }
}
