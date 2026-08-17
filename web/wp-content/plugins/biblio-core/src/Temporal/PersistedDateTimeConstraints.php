<?php

declare(strict_types=1);

namespace Biblio\Core\Temporal;

use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class PersistedDateTimeConstraints
{
    public const MINIMUM_YEAR = 1000;
    public const MAXIMUM_YEAR = 9999;

    public static function assertSupported(
        DateTimeImmutable $date,
        string $label
    ): void {
        $utcYear = (int) $date
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y");

        if ($utcYear < self::MINIMUM_YEAR || $utcYear > self::MAXIMUM_YEAR) {
            throw new ValidationException(
                "{$label} must be within the supported UTC year range "
                . self::MINIMUM_YEAR . "-" . self::MAXIMUM_YEAR . "."
            );
        }
    }

    private function __construct()
    {
    }
}
