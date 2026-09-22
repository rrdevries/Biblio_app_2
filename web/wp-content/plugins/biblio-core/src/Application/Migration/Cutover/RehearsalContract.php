<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** Shared strict primitives for locally supplied, privacy-safe receipts. */
final class RehearsalContract
{
    /**
 * @param array<string,mixed> $value
 * @param list<string> $keys
 */
    public static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        self::require($actual === $keys, "contract_fields_invalid");
    }

    /**
 * @phpstan-assert true $condition
 */
    public static function require(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new RehearsalFailure($reason);
        }
    }

    public static function hash(mixed $value): string
    {
        self::require(is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1, "digest_invalid");
        return (string) $value;
    }

    public static function id(mixed $value): string
    {
        self::require(is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,190}$/D', $value) === 1, "identity_invalid");
        return (string) $value;
    }

    public static function equal(mixed $actual, mixed $expected, string $reason): void
    {
        self::require(hash_equals(DeterministicJson::hash($expected), DeterministicJson::hash($actual)), $reason);
    }
}
