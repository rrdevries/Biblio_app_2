<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\Search;

interface BibliographicWorkSourceProvider
{
    public function key(): string;
    public function searchWorkSource(BibliographicTextSearchQuery $query, int $offset, int $capacity): BibliographicWorkSourcePage;
}
