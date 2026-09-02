<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

enum PreferredReadingSourceState: string
{
    case None = "none";
    case Available = "available";
    case Unavailable = "unavailable";
}
