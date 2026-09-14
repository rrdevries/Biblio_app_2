<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

interface MigrationCommandOutput
{
    public function line(string $message): void;

    public function error(string $message): never;
}
