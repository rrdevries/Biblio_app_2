<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

enum BibliographicAuthorMatchQuality: string
{
    case Exact = "exact";
    case Broader = "broader";

    public function sortTier(): int
    {
        return $this === self::Exact ? 0 : 1;
    }
}
