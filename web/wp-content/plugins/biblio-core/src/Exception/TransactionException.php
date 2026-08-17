<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

use RuntimeException;
use Throwable;

final class TransactionException extends RuntimeException implements CoreFailure
{
    public function __construct(
        string $message,
        private readonly FailureReason $failureReason,
        private readonly ?Throwable $operationFailure = null,
        private readonly ?Throwable $rollbackFailure = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): FailureReason
    {
        return $this->failureReason;
    }

    public function operationFailure(): ?Throwable
    {
        return $this->operationFailure;
    }

    public function rollbackFailure(): ?Throwable
    {
        return $this->rollbackFailure;
    }
}
