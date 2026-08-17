<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

use RuntimeException;
use Throwable;

class ConflictException extends RuntimeException implements CoreFailure
{
    public function __construct(
        string $message,
        private readonly FailureReason $failureReason,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): FailureReason
    {
        return $this->failureReason;
    }
}
