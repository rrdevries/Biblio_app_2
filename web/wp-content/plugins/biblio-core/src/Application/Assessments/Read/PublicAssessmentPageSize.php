<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class PublicAssessmentPageSize
{
    public const DEFAULT = 20;
    public const MAXIMUM = 50;

    public function __construct(private int $value = self::DEFAULT)
    {
        if ($value < 1 || $value > self::MAXIMUM) {
            throw new ValidationException(
                "Public assessment page size must be between 1 and "
                . self::MAXIMUM . "."
            );
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
