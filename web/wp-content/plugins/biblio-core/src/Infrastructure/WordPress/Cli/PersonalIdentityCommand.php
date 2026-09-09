<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Closure;
use Throwable;
use WP_User;

final class PersonalIdentityCommand
{
    /** @param Closure(): ?CoreApplication $application */
    public function __construct(private readonly Closure $application)
    {
    }

    /**
     * Create or safely reuse a normal personal user and designated Library.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     *
     * ## OPTIONS
     *
     * --user-login=<login>
     * : Exact local WordPress login; never inferred from the current actor.
     *
     * --user-email=<email>
     * : Exact local email used for identity matching and password reset mail.
     *
     * [--display-name=<name>]
     * : Optional non-secret display name.
     */
    public function bootstrap(array $args, array $assocArgs): void
    {
        unset($args);

        try {
            $user = (new WordPressPersonalUserProvisioner())->provision(
                $this->required($assocArgs, "user-login"),
                $this->required($assocArgs, "user-email"),
                $this->optional($assocArgs, "display-name")
            );
            $target = $this->core()->personalMigrationTargets()->bootstrap(
                $user->userId()
            );

            $this->render($target, $user->login(), $user->wasCreated());
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Validate explicit MIG-02 target IDs and report target cleanliness.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     *
     * ## OPTIONS
     *
     * --target-user-id=<id>
     * : Required existing active WordPress user ID.
     *
     * --target-library-id=<id>
     * : Required designated personal Library ID.
     *
     * [--require-empty]
     * : Fail when any target-owned content exists.
     */
    public function validate(array $args, array $assocArgs): void
    {
        unset($args);

        try {
            $target = $this->core()->personalMigrationTargets()->validate(
                new UserId($this->required($assocArgs, "target-user-id")),
                new LibraryId($this->required(
                    $assocArgs,
                    "target-library-id"
                ))
            );
            $user = get_userdata((int) $target->userId()->value());

            if (!$user instanceof WP_User) {
                throw new \RuntimeException(
                    "Validated target user disappeared before output."
                );
            }

            $this->render($target, (string) $user->user_login, false);

            if (
                array_key_exists("require-empty", $assocArgs)
                && !$target->readiness()->isClean()
            ) {
                $this->error(
                    "Target is non-empty and requires explicit operator review."
                );
            }
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function core(): CoreApplication
    {
        $application = ($this->application)();

        if (!$application instanceof CoreApplication) {
            throw new \RuntimeException(
                "Biblio Core is not initialized; inspect the lifecycle failure."
            );
        }

        return $application;
    }

    /** @param array<string, mixed> $assocArgs */
    private function required(array $assocArgs, string $key): string
    {
        $value = $assocArgs[$key] ?? null;

        if (!is_string($value) || trim($value) === "") {
            throw new \InvalidArgumentException(
                "Required --{$key} was not supplied."
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $assocArgs */
    private function optional(array $assocArgs, string $key): ?string
    {
        $value = $assocArgs[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Optional --{$key} must contain text."
            );
        }

        return $value;
    }

    private function render(
        PersonalMigrationTarget $target,
        string $login,
        bool $created
    ): void {
        $wordpressUser = get_userdata((int) $target->userId()->value());
        $roles = $wordpressUser instanceof WP_User
            ? array_values($wordpressUser->roles)
            : [];
        $payload = [
            "account" => $created ? "created" : "reused",
            "target_user_id" => $target->userId()->value(),
            "target_library_id" => $target->libraryId()->value(),
            "user_login" => $login,
            "library_name" => $target->libraryName()->value(),
            "membership_status" => "active",
            "management_role" => "owner",
            "use_access" => "direct",
            "wordpress_roles" => $roles,
            "super_admin" => is_super_admin(
                (int) $target->userId()->value()
            ),
            "cleanliness" => $target->readiness()->status(),
            "content_counts" => $target->readiness()->counts(),
        ];

        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT);

        if (!is_string($encoded)) {
            throw new \RuntimeException(
                "Could not encode migration target output."
            );
        }

        call_user_func(["WP_CLI", "line"], $encoded);
    }

    private function fail(Throwable $exception): never
    {
        $reason = $exception instanceof CoreFailure
            ? " [{$exception->reason()->value}]"
            : "";

        $this->error($exception->getMessage() . $reason);
    }

    private function error(string $message): never
    {
        call_user_func(["WP_CLI", "error"], $message);

        throw new \RuntimeException($message);
    }
}
