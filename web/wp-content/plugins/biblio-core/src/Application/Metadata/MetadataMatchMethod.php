<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataMatchMethod: string
{
    case ExactIsbn = "exact_isbn";
}
