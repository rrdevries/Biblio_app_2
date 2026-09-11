<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

enum BibliographicSearchResultKind: string
{
    case LocalCanonical = "local_canonical";
    case ExternalCandidate = "external_candidate";

    public function sortTier(): int
    {
        return $this === self::LocalCanonical ? 0 : 1;
    }
}
