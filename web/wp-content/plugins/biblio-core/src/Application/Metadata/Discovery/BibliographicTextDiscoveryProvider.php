<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

interface BibliographicTextDiscoveryProvider
{
    public function key(): string;

    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult;
}
