<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

use DomainException;

class AuthorizationException extends DomainException implements CoreFailure
{
    public function __construct(
        string $message = "Access denied.",
        private readonly FailureReason $failureReason =
            FailureReason::AuthorizationDenied
    ) {
        parent::__construct($message);
    }

    public function reason(): FailureReason
    {
        return $this->failureReason;
    }
}
