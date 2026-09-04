<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

use Biblio\Core\Exception\ValidationException;

final readonly class AdditionalPermissions
{
    public const CATALOG_ITEM_ADD = "catalog.item_add";
    public const CATALOG_CLASSIFICATION_MANAGE =
        "catalog.classification_manage";
    public const CONTRIBUTION_MODERATE = "contribution.moderate";
    public const COLLECTIONS_MANAGE = "collections";

    /** @param list<string> $values */
    private function __construct(private array $values)
    {
        $seen = [];

        foreach ($this->values as $value) {
            if (preg_match('//u', $value) !== 1) {
                throw new ValidationException(
                    "Additional permission must be valid UTF-8."
                );
            }

            if (trim($value) === "") {
                throw new ValidationException(
                    "Additional permission must not be empty."
                );
            }

            if (isset($seen[$value])) {
                throw new ValidationException(
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

    public function contains(string $permission): bool
    {
        return in_array($permission, $this->values, true);
    }

    public function with(string $permission): self
    {
        if ($this->contains($permission)) {
            return $this;
        }

        return new self([...$this->values, $permission]);
    }
}
