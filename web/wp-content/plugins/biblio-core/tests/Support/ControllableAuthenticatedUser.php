<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Support;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Identity\UserId;

final class ControllableAuthenticatedUser implements AuthenticatedUser
{
    public function __construct(private ?UserId $userId = null)
    {
    }

    public function authenticateAs(UserId $userId): void
    {
        $this->userId = $userId;
    }

    public function logOut(): void
    {
        $this->userId = null;
    }

    public function requireUserId(): UserId
    {
        return $this->userId ?? throw new AuthenticationException();
    }
}
