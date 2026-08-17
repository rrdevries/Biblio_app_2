<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence;

final readonly class DatabaseConflict
{
    public function __construct(
        private DatabaseConflictType $type,
        private string $constraintName
    ) {
    }

    public function type(): DatabaseConflictType
    {
        return $this->type;
    }

    public function constraintName(): string
    {
        return $this->constraintName;
    }
}
