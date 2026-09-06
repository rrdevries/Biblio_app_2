<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataField: string
{
    case Title = "title";
    case Subtitle = "subtitle";
    case Contributors = "contributors";
    case Languages = "languages";
    case Publishers = "publishers";
    case PublicationDate = "publication_date";
    case PageCount = "page_count";
    case Format = "format";
}
