<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

use InvalidArgumentException;

final class ValidationException extends InvalidArgumentException implements
    CoreFailure
{
    public function reason(): FailureReason
    {
        return FailureReason::ValidationFailed;
    }
}
