<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\DatabaseConflict;
use Biblio\Core\Infrastructure\Persistence\DatabaseConflictType;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use RuntimeException;

final class WpdbErrorTranslator
{
    public static function conflict(string $databaseError): ?DatabaseConflict
    {
        $matched = preg_match(
            "/Duplicate entry .* for key ['`]([^'`]+)['`]/i",
            $databaseError,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $qualifiedName = $matches[1];
        $separator = strrpos($qualifiedName, ".");
        $constraintName = $separator === false
            ? $qualifiedName
            : substr($qualifiedName, $separator + 1);

        return new DatabaseConflict(
            DatabaseConflictType::UniqueConstraint,
            $constraintName
        );
    }

    public static function writeFailure(
        string $message,
        string $databaseError
    ): PersistenceException {
        return new PersistenceException(
            $message,
            0,
            self::diagnostic("wpdb write", $databaseError),
            FailureReason::PersistenceWriteFailed
        );
    }

    public static function diagnostic(
        string $operation,
        string $databaseError
    ): RuntimeException {
        $detail = trim($databaseError);

        if ($detail === "") {
            $detail = "wpdb returned failure without diagnostic text";
        }

        return new RuntimeException("{$operation} failed: {$detail}");
    }
}
