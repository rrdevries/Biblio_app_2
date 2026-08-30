<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

enum ReadingHistorySourceType: string
{
    case LibraryItem = "library_item";
    case ExternalLoan = "external_loan";
    case Unknown = "unknown";
}
