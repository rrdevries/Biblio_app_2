<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum PersonalReadingTruthState: string
{
    case ReadKnownDateUnknown = "read_known_date_unknown";
    case ExplicitNotRead = "explicit_not_read";
    case Unknown = "unknown";
}
