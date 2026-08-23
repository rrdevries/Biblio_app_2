<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Exception\ValidationException;

final readonly class CatalogTextValue
{
    private function __construct(
        private CatalogDataState $state,
        private ?string $value
    ) {
        if ($state === CatalogDataState::Known && ($value === null || trim($value) === "")) {
            throw new ValidationException("Known catalog text must have a value.");
        }

        if ($state !== CatalogDataState::Known && $value !== null) {
            throw new ValidationException("Unavailable catalog text cannot have a value.");
        }
    }

    public static function known(string $value): self
    {
        return new self(CatalogDataState::Known, $value);
    }

    public static function missing(): self
    {
        return new self(CatalogDataState::Missing, null);
    }

    public static function notApplicable(): self
    {
        return new self(CatalogDataState::NotApplicable, null);
    }

    public static function unknown(): self
    {
        return new self(CatalogDataState::Unknown, null);
    }

    public function state(): CatalogDataState { return $this->state; }
    public function value(): ?string { return $this->value; }
}
