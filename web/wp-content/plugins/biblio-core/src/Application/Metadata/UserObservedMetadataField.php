<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum UserObservedMetadataField: string
{
    case Isbn = "isbn";
    case Title = "title";
    case Subtitle = "subtitle";
    case Contributors = "contributors";
    case Languages = "languages";
    case Publishers = "publishers";
    case PublicationDate = "publication_date";
    case EditionStatement = "edition_statement";
    case Format = "format";
    case PageCount = "page_count";
}
