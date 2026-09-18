<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Identity\IdentifierConstraints;

final class CurrentV1ContainedWorkSourceIds
{
    public static function work(string $bookId, int $slot): string
    {
        return self::valid(
            "v1.book/{$bookId}/contained-work/{$slot}",
            "Contained Work source ID"
        );
    }

    public static function containment(string $bookId, int $slot): string
    {
        return self::valid(
            self::work($bookId, $slot) . "/containment",
            "Contained Work relation source ID"
        );
    }

    public static function authorOccurrence(string $bookId, int $slot): string
    {
        return self::valid(
            self::work($bookId, $slot) . "/author",
            "Contained Author occurrence source ID"
        );
    }

    public static function author(
        string $bookId,
        int $slot,
        string $observedDisplayName
    ): string {
        $normalized = AuthorContributorCreditKey::normalizeObservedName(
            $observedDisplayName
        );
        $hash = hash(
            "sha256",
            "current-v1-contained-author-occurrence-name-v1\0" . $normalized
        );
        return self::valid(
            self::authorOccurrence($bookId, $slot)
                . "/name/{$hash}/author",
            "Contained Author source ID"
        );
    }

    public static function series(string $bookId, int $slot): string
    {
        return CurrentV1SeriesSourceIds::contained($bookId, $slot);
    }

    public static function isbn(string $bookId, int $slot): string
    {
        return self::valid(
            self::work($bookId, $slot) . "/isbn",
            "Contained ISBN source ID"
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
