<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

enum LocalEditionResolutionType: string
{
    case LocalExact = "local_exact";
    case LocalNone = "local_none";
    case LocalAmbiguous = "local_ambiguous";
}
