<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;

final class PersonalMigrationTargetInvalid extends ConflictException
{
    public function __construct(string $message)
    {
        parent::__construct(
            $message,
            FailureReason::PersonalMigrationTargetInvalid
        );
    }
}
