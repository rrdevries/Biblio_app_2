<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Exception\ValidationException;

final readonly class ActivityPayload
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
        self::assertObject($this->values, "Activity payload");
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @param array<array-key, mixed> $values */
    private static function assertObject(array $values, string $path): void
    {
        foreach ($values as $key => $value) {
            if (
                !is_string($key)
                || $key === ""
                || preg_match('//u', $key) !== 1
            ) {
                throw new ValidationException(
                    "{$path} must use non-empty valid UTF-8 property names."
                );
            }

            self::assertValue($value, "{$path}.{$key}");
        }
    }

    private static function assertValue(mixed $value, string $path): void
    {
        if (
            $value === null
            || is_int($value)
            || is_bool($value)
        ) {
            return;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new ValidationException(
                    "{$path} must contain valid UTF-8 strings."
                );
            }

            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new ValidationException(
                    "{$path} must contain a finite number."
                );
            }

            return;
        }

        if (!is_array($value)) {
            throw new ValidationException(
                "{$path} must contain only JSON-compatible snapshot data."
            );
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::assertValue($item, "{$path}[{$index}]");
            }

            return;
        }

        self::assertObject($value, $path);
    }
}
