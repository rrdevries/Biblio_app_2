<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Exception\ValidationException;

final readonly class CatalogSearchTerm
{
    public const MAXIMUM_LENGTH = 191;

    public function __construct(private string $value)
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ValidationException('Catalog search must be valid UTF-8.');
        }
        $length = mb_strlen($value);
        if ($value !== trim($value) || $length < 2 || $length > self::MAXIMUM_LENGTH) {
            throw new ValidationException('Catalog search must contain 2 to 191 trimmed characters.');
        }
    }

    public function value(): string { return $this->value; }
}
