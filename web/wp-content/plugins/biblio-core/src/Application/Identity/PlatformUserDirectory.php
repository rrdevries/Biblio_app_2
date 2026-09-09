<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Identity\UserId;

interface PlatformUserDirectory
{
    public function isActive(UserId $userId): bool;
}
