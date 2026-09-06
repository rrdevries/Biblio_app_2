<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;
use JsonException;

final readonly class MetadataFieldValue
{
    private string $json;
    private string $hash;

    /** @param string|int|list<string> $value */
    public function __construct(private string|int|array $value)
    {
        self::assertValue($value);

        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Invalid metadata field value.", 0, $exception);
        }

        if (strlen($json) > 8192) {
            throw new InvalidArgumentException("Metadata field value is too large.");
        }

        $this->json = $json;
        $this->hash = hash("sha256", $json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Stored metadata field value is invalid.", 0, $exception);
        }

        if (!is_string($value) && !is_int($value) && !is_array($value)) {
            throw new InvalidArgumentException("Stored metadata field value has an invalid type.");
        }

        /** @var string|int|list<string> $value */
        return new self($value);
    }

    /** @return string|int|list<string> */
    public function value(): string|int|array { return $this->value; }
    public function json(): string { return $this->json; }
    public function hash(): string { return $this->hash; }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }

    /** @param string|int|array<array-key, mixed> $value */
    private static function assertValue(string|int|array $value): void
    {
        if (is_string($value)) {
            if (
                $value === ""
                || trim($value) !== $value
                || !mb_check_encoding($value, "UTF-8")
                || mb_strlen($value) > 512
            ) {
                throw new InvalidArgumentException("Invalid metadata text value.");
            }
            return;
        }

        if (is_int($value)) {
            if ($value < 1 || $value > 100000) {
                throw new InvalidArgumentException("Invalid metadata integer value.");
            }
            return;
        }

        if ($value === [] || !array_is_list($value) || count($value) > 32) {
            throw new InvalidArgumentException("Invalid metadata list value.");
        }

        foreach ($value as $entry) {
            if (
                !is_string($entry)
                || $entry === ""
                || trim($entry) !== $entry
                || !mb_check_encoding($entry, "UTF-8")
                || mb_strlen($entry) > 512
            ) {
                throw new InvalidArgumentException("Invalid metadata list entry.");
            }
        }
    }
}
