<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class PaidAmount
{
    private string $decimal;

    public function __construct(string $decimal, private Iso4217Currency $currency)
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?$/D', $decimal) !== 1) {
            throw new ValidationException("Paid amount is invalid.");
        }

        $canonical = str_contains($decimal, ".")
            ? rtrim(rtrim($decimal, "0"), ".")
            : $decimal;
        $this->decimal = $canonical === "" ? "0" : $canonical;
    }

    public function decimal(): string { return $this->decimal; }
    public function currency(): Iso4217Currency { return $this->currency; }

    public function equals(self $other): bool
    {
        return $this->decimal === $other->decimal
            && $this->currency->value() === $other->currency->value();
    }
}
