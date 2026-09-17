<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

final class CurrentV1ReadingSourceIds
{
    public static function registration(string $bookId): string
    {
        return "v1.book/{$bookId}/read-registration";
    }

    public static function status(string $bookId): string
    {
        return "v1.book/{$bookId}/read-status";
    }

    private function __construct()
    {
    }
}
