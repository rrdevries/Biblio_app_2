<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Identity\IdentifierConstraints;

final class CurrentV1AuthorSourceIds
{
    public static function stableAuthor(string $authorId): string
    {
        return self::valid("v1.author/{$authorId}", "Stable Author source ID");
    }

    public static function occurrence(string $bookId, int $position): string
    {
        return self::valid(
            "v1.book/{$bookId}/author-occurrence/{$position}",
            "Author occurrence source ID"
        );
    }

    public static function occurrenceAuthor(
        string $bookId,
        int $position,
        string $observedDisplayName
    ): string {
        $normalized = AuthorContributorCreditKey::normalizeObservedName(
            $observedDisplayName
        );
        $hash = hash(
            "sha256",
            "current-v1-author-occurrence-name-v1\0" . $normalized
        );

        return self::valid(
            self::occurrence($bookId, $position) . "/name/{$hash}/author",
            "Occurrence-scoped Author source ID"
        );
    }

    public static function containedWorkAuthor(string $bookId, int $position): string
    {
        return self::valid(
            "v1.book/{$bookId}/contained-work/{$position}/author",
            "Contained-work Author source ID"
        );
    }

    private static function valid(string $value, string $label): string
    {
        IdentifierConstraints::assertValid($value, $label);
        return $value;
    }

    private function __construct()
    {
    }
}
