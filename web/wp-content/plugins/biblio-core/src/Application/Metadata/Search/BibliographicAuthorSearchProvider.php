<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicAuthorSearchProvider
{
    public function key(): string;

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        int $offset = 0,
        int $limit = BibliographicTextSearchService::PAGE_SIZE
    ): BibliographicAuthorSearchSourcePage;
}
