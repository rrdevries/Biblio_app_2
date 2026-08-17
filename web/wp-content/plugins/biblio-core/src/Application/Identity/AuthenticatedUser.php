<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Identity\UserId;

interface AuthenticatedUser
{
    /** @throws AuthenticationException */
    public function requireUserId(): UserId;
}
