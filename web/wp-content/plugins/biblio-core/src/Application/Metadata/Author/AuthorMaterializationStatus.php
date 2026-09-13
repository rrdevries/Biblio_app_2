<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorMaterializationStatus: string
{
    case Materialized = "materialized";
    case IdentityConflict = "identity_conflict";
    case PositionConflict = "position_conflict";
}
