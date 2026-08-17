<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence;

enum DatabaseConflictType: string
{
    case UniqueConstraint = "unique_constraint";
}
