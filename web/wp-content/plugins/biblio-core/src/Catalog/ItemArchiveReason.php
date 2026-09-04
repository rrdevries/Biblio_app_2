<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum ItemArchiveReason: string
{
    case Sold = "sold";
    case GivenAway = "given_away";
    case Donated = "donated";
    case Lost = "lost";
    case DamagedDiscarded = "damaged_discarded";
    case NotReturned = "not_returned";
}
