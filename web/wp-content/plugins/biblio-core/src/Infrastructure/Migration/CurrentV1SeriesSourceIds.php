<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

final class CurrentV1SeriesSourceIds
{
    public static function series(string $exactName): string
    {
        return "v1.series/exact-raw-name-v1/"
            . rtrim(strtr(base64_encode($exactName), "+/", "-_"), "=");
    }

    public static function membership(string $bookId): string
    {
        return "v1.book/{$bookId}/series-membership";
    }

    public static function unsafePosition(string $bookId): string
    {
        return "v1.book/{$bookId}/series-position";
    }

    public static function contained(string $bookId, int $oneBasedSlot): string
    {
        return "v1.book/{$bookId}/contained-work/{$oneBasedSlot}/series";
    }

    private function __construct() {}
}
