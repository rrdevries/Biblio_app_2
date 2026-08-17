<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Identity;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Identity\UserId;
use WP_User;

final class WordPressAuthenticatedUser implements AuthenticatedUser
{
    public function requireUserId(): UserId
    {
        $wordpressUserId = get_current_user_id();

        if ($wordpressUserId <= 0) {
            throw new AuthenticationException();
        }

        $wordpressUser = get_userdata($wordpressUserId);

        if (
            !$wordpressUser instanceof WP_User
            || (int) $wordpressUser->ID !== $wordpressUserId
        ) {
            throw new AuthenticationException();
        }

        return new UserId((string) $wordpressUserId);
    }
}
