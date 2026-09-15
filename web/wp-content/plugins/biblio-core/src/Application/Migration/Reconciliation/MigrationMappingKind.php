<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

enum MigrationMappingKind: string
{
    case Entity = "entity";
    case Relation = "relation";
}
