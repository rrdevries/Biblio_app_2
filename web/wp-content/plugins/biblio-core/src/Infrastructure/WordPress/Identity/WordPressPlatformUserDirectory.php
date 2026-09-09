<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Identity;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Identity\UserId;
use WP_User;

final class WordPressPlatformUserDirectory implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool
    {
        $value = $userId->value();

        if (!ctype_digit($value) || (string) (int) $value !== $value) {
            return false;
        }

        $user = get_userdata((int) $value);

        return $user instanceof WP_User
            && (int) $user->ID === (int) $value
            && (int) $user->user_status === 0;
    }
}
