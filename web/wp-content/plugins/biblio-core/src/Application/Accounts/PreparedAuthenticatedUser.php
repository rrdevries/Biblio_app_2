<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Accounts;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Identity\UserId;

final readonly class PreparedAuthenticatedUser implements AuthenticatedUser
{
    public function __construct(private AuthenticatedUser $actor, private AccountPreparationService $accounts) {}

    public function requireUserId(): UserId
    {
        $id = $this->actor->requireUserId();
        $this->accounts->requireReady($id);
        return $id;
    }
}
