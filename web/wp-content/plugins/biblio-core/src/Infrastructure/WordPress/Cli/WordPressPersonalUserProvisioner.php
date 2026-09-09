<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use WP_Error;
use WP_User;

final class WordPressPersonalUserProvisioner
{
    public function provision(
        string $login,
        string $email,
        ?string $displayName
    ): ProvisionedPersonalUser {
        $this->assertLocalDdev();
        $login = trim($login);
        $email = trim($email);
        $displayName = $displayName === null ? null : trim($displayName);

        if (
            $login === ""
            || !validate_username($login)
            || sanitize_user($login, true) !== $login
        ) {
            throw new ValidationException(
                "Provide an exact valid WordPress user login."
            );
        }

        if (!is_email($email)) {
            throw new ValidationException(
                "Provide an exact valid email address."
            );
        }

        if ($displayName !== null && $displayName === "") {
            throw new ValidationException(
                "Display name cannot be empty when supplied."
            );
        }

        $existing = get_user_by("login", $login);

        if ($existing instanceof WP_User) {
            $this->assertMatchingNormalUser($existing, $email, $displayName);

            return new ProvisionedPersonalUser(
                new UserId((string) $existing->ID),
                (string) $existing->user_login,
                false
            );
        }

        $data = [
            "user_login" => $login,
            "user_email" => $email,
            "user_pass" => wp_generate_password(32, true, true),
            "role" => "subscriber",
        ];

        if ($displayName !== null) {
            $data["display_name"] = $displayName;
        }

        $result = wp_insert_user($data);

        if ($result instanceof WP_Error) {
            throw new ValidationException(
                "WordPress rejected the personal user account input."
            );
        }

        wp_new_user_notification($result, null, "user");

        return new ProvisionedPersonalUser(
            new UserId((string) $result),
            $login,
            true
        );
    }

    private function assertMatchingNormalUser(
        WP_User $user,
        string $email,
        ?string $displayName
    ): void {
        if (
            strcasecmp((string) $user->user_email, $email) !== 0
            || (int) $user->user_status !== 0
            || ($displayName !== null && $user->display_name !== $displayName)
            || $user->roles !== ["subscriber"]
        ) {
            throw new ValidationException(
                "The existing login does not match the requested normal personal identity."
            );
        }
    }

    private function assertLocalDdev(): void
    {
        if (
            wp_get_environment_type() !== "local"
            || !is_string(getenv("DDEV_PROJECT"))
            || getenv("DDEV_PROJECT") === ""
        ) {
            throw new ValidationException(
                "Personal identity bootstrap is restricted to local DDEV."
            );
        }
    }
}
