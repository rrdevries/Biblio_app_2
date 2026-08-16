<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use InvalidArgumentException;

final readonly class AdditionalPermissions
{
    /** @param list<string> $values */
    private function __construct(private array $values)
    {
        $seen = [];

        foreach ($this->values as $value) {
            if (trim($value) === "") {
                throw new InvalidArgumentException(
                    "Additional permission must not be empty."
                );
            }

            if (isset($seen[$value])) {
                throw new InvalidArgumentException(
                    "Additional permissions must be unique."
                );
            }

            $seen[$value] = true;
        }
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function fromValues(string ...$values): self
    {
        return new self($values);
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->values;
    }
}
