<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

final readonly class WordPressMigrationCommandOutput implements MigrationCommandOutput
{
    public function line(string $message): void
    {
        call_user_func(["WP_CLI", "line"], $message);
    }

    public function error(string $message): never
    {
        call_user_func(["WP_CLI", "error"], $message);
        throw new \RuntimeException($message);
    }
}
