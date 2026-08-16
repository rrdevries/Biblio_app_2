<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

enum MembershipStatus: string
{
    case Active = "active";
    case Inactive = "inactive";
}
