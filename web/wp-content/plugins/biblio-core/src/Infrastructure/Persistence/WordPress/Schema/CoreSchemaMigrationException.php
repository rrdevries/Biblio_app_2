<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use RuntimeException;

final class CoreSchemaMigrationException extends RuntimeException implements
    CoreFailure
{
    public function reason(): FailureReason
    {
        return FailureReason::SchemaMigrationFailed;
    }
}
