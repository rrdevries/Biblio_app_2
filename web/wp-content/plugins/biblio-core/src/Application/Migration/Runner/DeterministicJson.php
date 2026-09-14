<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;
use JsonException;

final class DeterministicJson
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | ($pretty ? JSON_PRETTY_PRINT : 0)
            );
        } catch (JsonException $exception) {
            throw new ValidationException(
                "Migration data must be JSON-compatible.",
                0,
                $exception
            );
        }
    }

    public static function hash(mixed $value): string
    {
        return hash("sha256", self::encode($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (
                $value === null
                || is_string($value)
                || is_int($value)
                || is_bool($value)
                || is_float($value) && is_finite($value)
            ) {
                return $value;
            }

            throw new ValidationException(
                "Migration data contains an unsupported value."
            );
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key) || $key === "" || preg_match('//u', $key) !== 1) {
                throw new ValidationException(
                    "Migration object keys must be non-empty UTF-8 strings."
                );
            }
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
