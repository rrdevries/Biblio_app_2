<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicWorkSearchProvider
{
    public function key(): string;

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage;
}
