<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class AuthorContributorCreditSourceIdentity
{
    private function __construct(private string $value)
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new ValidationException(
                "Author credit source identity must be a SHA-256 value."
            );
        }
    }

    public static function provider(
        string $provider,
        string $sourceEntityType,
        string $sourceRecordId,
        int $sourcePosition
    ): self {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider) !== 1) {
            throw new ValidationException("Provider key is invalid.");
        }
        if (!in_array($sourceEntityType, ["work", "edition"], true)) {
            throw new ValidationException("Provider source entity type is invalid.");
        }
        IdentifierConstraints::assertValid($sourceRecordId, "Provider source record ID");
        if ($sourcePosition < 1) {
            throw new ValidationException("Provider source position must be positive.");
        }

        return self::derive([
            "provider",
            $provider,
            $sourceEntityType,
            $sourceRecordId,
            (string) $sourcePosition,
        ]);
    }

    public static function userObservation(string $observationId): self
    {
        IdentifierConstraints::assertValid($observationId, "User observation ID");
        return self::derive(["user_observation", $observationId]);
    }

    public static function migration(string $observationId): self
    {
        IdentifierConstraints::assertValid($observationId, "Migration observation ID");
        return self::derive(["migration", $observationId]);
    }

    public static function stored(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @param list<string> $parts */
    private static function derive(array $parts): self
    {
        foreach ($parts as $part) {
            if (str_contains($part, "\0")) {
                throw new ValidationException(
                    "Author credit source identity parts must not contain NUL."
                );
            }
        }

        return new self(hash(
            "sha256",
            implode("\0", ["author-credit-source-v1", ...$parts])
        ));
    }
}
