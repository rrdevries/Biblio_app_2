<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use RuntimeException;
use Throwable;

final class PersistenceException extends RuntimeException implements CoreFailure
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly FailureReason $failureReason =
            FailureReason::PersistenceFailure
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function reason(): FailureReason
    {
        return $this->failureReason;
    }
}
