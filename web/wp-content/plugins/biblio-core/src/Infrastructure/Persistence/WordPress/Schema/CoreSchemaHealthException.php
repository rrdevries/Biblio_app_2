<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use RuntimeException;

final class CoreSchemaHealthException extends RuntimeException implements
    CoreFailure
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

    public function reason(): FailureReason
    {
        return FailureReason::SchemaHealthFailed;
    }
}
