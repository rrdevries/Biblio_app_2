<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

enum MigrationRunStatus: string
{
    case Planned = "planned";
    case Running = "running";
    case Interrupted = "interrupted";
    case Failed = "failed";
    case Completed = "completed";
}
