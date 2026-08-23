<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

enum NextReadingTargetType: string
{
    case Work = "work";
    case LibraryItem = "library_item";
    case ExternalLoan = "external_loan";
}
