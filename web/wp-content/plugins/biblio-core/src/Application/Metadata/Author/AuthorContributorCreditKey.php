<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition,ContributorRole,WorkId};
use Biblio\Core\Exception\ValidationException;

final readonly class AuthorContributorCreditKey
{
    private function __construct(
        private string $value,
        private string $normalizedNameHash
    ) {
        if (
            preg_match('/^[0-9a-f]{64}$/D', $value) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $normalizedNameHash) !== 1
        ) {
            throw new ValidationException(
                "Author contributor credit hashes must be SHA-256 values."
            );
        }
    }

    public static function fromSource(
        WorkId $workId,
        ContributorRole $role,
        ContributorPosition $position,
        string $observedDisplayName,
        AuthorContributorCreditSourceIdentity $sourceIdentity
    ): self {
        $normalized = self::normalizeObservedName($observedDisplayName);
        $parts = [
            "author-credit-v1",
            $workId->value(),
            $role->value,
            (string) $position->value(),
            $normalized,
            $sourceIdentity->value(),
        ];
        foreach ($parts as $part) {
            if (str_contains($part, "\0")) {
                throw new ValidationException(
                    "Author contributor credit values must not contain NUL."
                );
            }
        }

        return new self(
            hash("sha256", implode("\0", $parts)),
            hash("sha256", $normalized)
        );
    }

    public static function stored(string $value, string $normalizedNameHash): self
    {
        return new self($value, $normalizedNameHash);
    }

    public static function normalizeObservedName(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new ValidationException(
                "Observed Author display name must not contain NUL."
            );
        }
        if (preg_match('//u', $value) !== 1) {
            throw new ValidationException(
                "Observed Author display name must be valid UTF-8."
            );
        }
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
        if (!is_string($normalized)) {
            throw new ValidationException(
                "Observed Author display name could not be normalized."
            );
        }
        $normalized = trim($normalized, " ");
        $length = preg_match_all('/./us', $normalized);
        if ($length === false || $length < 1 || $length > 512) {
            throw new ValidationException(
                "Observed Author display name must contain 1 to 512 characters."
            );
        }

        return $normalized;
    }

    public function value(): string { return $this->value; }
    public function normalizedNameHash(): string
    {
        return $this->normalizedNameHash;
    }
}
