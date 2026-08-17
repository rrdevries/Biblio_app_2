<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

final class AuthenticationException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(
            "Authentication is required.",
            FailureReason::AuthenticationRequired
        );
    }
}
