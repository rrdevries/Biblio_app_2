<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\Search;
use Biblio\Core\Catalog\WorkId;

interface BibliographicLocalWorkQueryMatcher
{
    public function workMatchesQuery(WorkId $workId, BibliographicTextSearchQuery $query): bool;
}
