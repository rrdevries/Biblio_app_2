<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Exception\ValidationException;

final readonly class ManualAuthorInput
{
    private function __construct(private string $displayName)
    {
    }

    public static function fromDisplayName(string $displayName): ?self
    {
        if (preg_match('//u', $displayName) !== 1) {
            throw new ValidationException(
                "Manual Author display name must be valid UTF-8."
            );
        }
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $displayName);
        if (!is_string($normalized)) {
            throw new ValidationException(
                "Manual Author display name could not be normalized."
            );
        }
        $normalized = trim($normalized, " ");
        if ($normalized === "") {
            return null;
        }

        return new self(
            AuthorContributorCreditKey::normalizeObservedName($normalized)
        );
    }

    public function displayName(): string
    {
        return $this->displayName;
    }
}
