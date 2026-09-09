<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;
use JsonException;

final class MigrationEvidence
{
    public const MAX_JSON_BYTES = 65535;

    /** @param array<string, mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        try {
            $json = json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new ValidationException(
                "Migration evidence must be JSON-compatible.",
                0,
                $exception
            );
        }

        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new ValidationException(
                "Migration evidence exceeds the 65535-byte storage limit."
            );
        }

        return $json;
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash("sha256", self::canonicalJson($payload));
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
                "Migration evidence contains an unsupported value."
            );
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key) || $key === "" || preg_match('//u', $key) !== 1) {
                throw new ValidationException(
                    "Migration evidence object keys must be non-empty UTF-8 strings."
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
