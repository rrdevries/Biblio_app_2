<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

enum ClassificationTermConflictType: string
{
    case Identifier = "identifier";
    case NormalizedName = "normalized_name";
    case SeedKey = "seed_key";
}
