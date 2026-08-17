<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

interface CoreSchemaMigration
{
    public function sourceVersion(): int;

    public function targetVersion(): int;

    public function assertPrecondition(): void;

    public function migrate(): void;

    public function assertPostcondition(): void;
}
