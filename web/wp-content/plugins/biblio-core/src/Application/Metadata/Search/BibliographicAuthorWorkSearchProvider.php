<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicAuthorWorkSearchProvider
{
    public function key(): string;

    public function searchWorksForAuthor(
        BibliographicAuthorReference $author,
        int $offset,
        int $limit
    ): BibliographicAuthorWorkProviderPage;
}
