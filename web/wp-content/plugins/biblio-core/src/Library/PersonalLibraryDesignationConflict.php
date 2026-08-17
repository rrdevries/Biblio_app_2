<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Throwable;

final class PersonalLibraryDesignationConflict extends ConflictException
{
    public function __construct(
        FailureReason $reason = FailureReason::PersonalLibraryDesignationConflict,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            "A personal Library designation conflicts with existing state.",
            $reason,
            $previous
        );
    }
}
