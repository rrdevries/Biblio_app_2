<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Identity;

use Biblio\Core\Accounts\AccountDirectory;
use Biblio\Core\Identity\UserId;

final readonly class WordPressAccountDirectory implements AccountDirectory
{
    public const INTENT_KEY = "_biblio_account_intent";

    public function isActive(UserId $userId): bool
    {
        return (new WordPressPlatformUserDirectory())->isActive($userId);
    }

    public function isRequested(UserId $userId): bool
    {
        return get_user_meta((int) $userId->value(), self::INTENT_KEY, true) === "1";
    }

    public function canManageAccounts(UserId $actor): bool
    {
        return $this->isActive($actor) && user_can((int) $actor->value(), "create_users");
    }
}
