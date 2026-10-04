<?php

declare(strict_types=1);

namespace Biblio\UI;

final class AccountMount
{
    public static function attributes(): string
    {
        if (!is_user_logged_in()) {
            return 'data-account-state="guest"';
        }

        $user = wp_get_current_user();
        $name = trim((string) ($user->display_name ?: $user->user_login));

        return sprintf(
            'data-account-state="authenticated" data-account-name="%s" data-logout-url="%s"',
            esc_attr($name),
            esc_url(wp_logout_url(home_url("/")))
        );
    }
}
