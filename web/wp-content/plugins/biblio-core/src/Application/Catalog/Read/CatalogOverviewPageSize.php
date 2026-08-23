<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class CatalogOverviewPageSize
{
    public const DEFAULT = 24;
    public const MAXIMUM = 100;

    public function __construct(private int $value = self::DEFAULT)
    {
        if ($value < 1 || $value > self::MAXIMUM) {
            throw new ValidationException(
                "Catalog overview page size must be between 1 and "
                . self::MAXIMUM . "."
            );
        }
    }

    public function value(): int { return $this->value; }
}
