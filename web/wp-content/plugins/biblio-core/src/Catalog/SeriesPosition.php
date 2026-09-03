<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class SeriesPosition
{
    private const PATTERN = '/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,6}))?$/D';

    private function __construct(private ?string $value)
    {
    }

    public static function unknown(): self { return new self(null); }

    public static function known(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new ValidationException("Series position is invalid.");
        }

        $normalized = ltrim($matches[1], "0");
        $normalized = $normalized === "" ? "0" : $normalized;
        if (isset($matches[2])) {
            $fraction = rtrim($matches[2], "0");
            if ($fraction !== "") {
                $normalized .= "." . $fraction;
            }
        }

        return new self($normalized);
    }

    public function isKnown(): bool { return $this->value !== null; }
    public function value(): ?string { return $this->value; }
}
