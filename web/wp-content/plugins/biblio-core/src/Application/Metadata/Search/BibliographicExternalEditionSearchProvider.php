<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicExternalEditionSearchProvider
{
    public function key(): string;

    public function searchEditionsForProviderWork(
        BibliographicWorkReference $parentWork,
        BibliographicProviderEntityIdentity $providerWork,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage;
}
