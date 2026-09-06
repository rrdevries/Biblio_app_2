<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class MetadataLookupSnapshotUnavailable extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            "The reviewed metadata snapshot is unavailable or expired.",
            FailureReason::MetadataLookupSnapshotUnavailable
        );
    }
}
