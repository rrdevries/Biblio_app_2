<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

enum PreservedSourceEvidencePrivacy: string
{
    case OrdinarySource = "ordinary_source";
    case RestrictedSource = "restricted_source";
}
