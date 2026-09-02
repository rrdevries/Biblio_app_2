<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

enum PreferredReadingSourceType: string
{
    case LibraryItem = "library_item";
    case ExternalLoan = "external_loan";
}
