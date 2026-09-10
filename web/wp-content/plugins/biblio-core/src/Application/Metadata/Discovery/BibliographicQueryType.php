<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

enum BibliographicQueryType: string
{
    case Isbn = "isbn";
    case Text = "text";
}
