<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum PersonalWorkReadingStatus: string
{
    case Reading = "reading";
    case Read = "read";
    case NotRead = "not_read";
}
