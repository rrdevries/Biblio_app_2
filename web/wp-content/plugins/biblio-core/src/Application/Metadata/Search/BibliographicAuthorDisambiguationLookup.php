<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\AuthorId;

interface BibliographicAuthorDisambiguationLookup
{
    /**
     * @param list<AuthorId> $authorIds
     * @return array<string,BibliographicAuthorDisambiguation>
     */
    public function localAuthorDisambiguations(array $authorIds): array;
}
