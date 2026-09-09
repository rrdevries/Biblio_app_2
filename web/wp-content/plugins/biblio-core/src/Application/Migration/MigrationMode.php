<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

enum MigrationMode: string
{
    case DryRun = "dry_run";
    case Apply = "apply";
}
