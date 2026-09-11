<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

enum BibliographicEditionSearchLane: string
{
    case Local = "local";
    case External = "external";
}
