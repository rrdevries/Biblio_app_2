<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class CatalogTextListValue
{
    /** @param list<string> $values */
    private function __construct(
        private CatalogDataState $state,
        private array $values
    ) {
        if ($state === CatalogDataState::Known && $values === []) {
            throw new ValidationException("Known catalog text list must have values.");
        }

        if ($state !== CatalogDataState::Known && $values !== []) {
            throw new ValidationException("Unavailable catalog text list cannot have values.");
        }

        foreach ($values as $value) {
            if (trim($value) === "") {
                throw new ValidationException("Catalog text list values must not be empty.");
            }
        }
    }

    /** @param non-empty-list<string> $values */
    public static function known(array $values): self
    {
        return new self(CatalogDataState::Known, $values);
    }

    public static function missing(): self
    {
        return new self(CatalogDataState::Missing, []);
    }

    public static function notApplicable(): self
    {
        return new self(CatalogDataState::NotApplicable, []);
    }

    public static function unknown(): self
    {
        return new self(CatalogDataState::Unknown, []);
    }

    public function state(): CatalogDataState { return $this->state; }

    /** @return list<string> */
    public function values(): array { return $this->values; }
}
