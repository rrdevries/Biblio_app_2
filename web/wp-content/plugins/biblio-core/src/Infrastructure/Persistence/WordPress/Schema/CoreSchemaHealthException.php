<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use RuntimeException;

final class CoreSchemaHealthException extends RuntimeException
{
    public function __construct(private readonly CoreSchemaHealth $health)
    {
        parent::__construct(
            "Biblio Core schema health failure; no automatic repair was "
            . "attempted: " . $health->summary()
        );
    }

    public function health(): CoreSchemaHealth
    {
        return $this->health;
    }
}
