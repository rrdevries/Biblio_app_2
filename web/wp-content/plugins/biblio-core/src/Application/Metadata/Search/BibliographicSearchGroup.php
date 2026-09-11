<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

enum BibliographicSearchGroup: string
{
    case Authors = "authors";
    case Works = "works";
}
