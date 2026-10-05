<?php

declare(strict_types=1);

namespace Biblio\Core\Accounts;

use Biblio\Core\Identity\UserId;

interface AccountDirectory
{
    public function isActive(UserId $userId): bool;
    public function isRequested(UserId $userId): bool;
    public function canManageAccounts(UserId $actor): bool;
}
