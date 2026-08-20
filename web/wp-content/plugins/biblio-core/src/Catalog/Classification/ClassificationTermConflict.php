<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class ClassificationTermConflict extends ConflictException
{
    public function __construct(
        private readonly ClassificationTermConflictType $conflictType,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            "A Library classification term conflicts with existing data.",
            FailureReason::ClassificationTermConflict,
            $previous
        );
    }

    public function conflictType(): ClassificationTermConflictType
    {
        return $this->conflictType;
    }
}
