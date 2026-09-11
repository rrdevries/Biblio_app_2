<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicEditionSearchProvider
{
    public function key(): string;

    public function searchEditions(
        BibliographicWorkReference $work,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage;
}
