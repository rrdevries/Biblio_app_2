<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataEvidenceQueryType: string
{
    case Isbn10 = "isbn_10";
    case Isbn13 = "isbn_13";
    case Text = "text";
}
