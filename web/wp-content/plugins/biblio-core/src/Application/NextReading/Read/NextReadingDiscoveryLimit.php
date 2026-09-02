<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class NextReadingDiscoveryLimit
{
    public const DEFAULT = 10;
    public const MAXIMUM = 25;

    public function __construct(private int $value = self::DEFAULT)
    {
        if ($value < 1 || $value > self::MAXIMUM) {
            throw new ValidationException(
                "Next Reading discovery limit must be between 1 and "
                . self::MAXIMUM . "."
            );
        }
    }

    public function value(): int { return $this->value; }
}
