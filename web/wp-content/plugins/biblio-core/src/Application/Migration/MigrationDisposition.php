<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

enum MigrationDisposition: string
{
    case Mapped = "mapped";
    case Transformed = "transformed";
    case PreservedDeferred = "preserved_deferred";
    case Quarantined = "quarantined";
    case IntentionallyDropped = "intentionally_dropped";
    case Failed = "failed";
}
