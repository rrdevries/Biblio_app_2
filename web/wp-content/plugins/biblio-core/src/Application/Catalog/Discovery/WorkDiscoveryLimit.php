<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkDiscoveryLimit
{
    public const DEFAULT = 10;
    public const MAXIMUM = 25;

    public function __construct(private int $value = self::DEFAULT)
    {
        if ($value < 1 || $value > self::MAXIMUM) {
            throw new ValidationException(
                "Work discovery limit must be between 1 and " . self::MAXIMUM . "."
            );
        }
    }

    public function value(): int { return $this->value; }
}
