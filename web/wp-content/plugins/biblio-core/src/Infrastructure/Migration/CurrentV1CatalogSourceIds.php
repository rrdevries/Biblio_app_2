<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

final class CurrentV1CatalogSourceIds
{
    public static function work(string $bookId): string
    {
        return "v1.book/{$bookId}/work";
    }

    public static function edition(string $bookId): string
    {
        return "v1.book/{$bookId}/edition";
    }

    public static function item(string $copyId): string
    {
        return "v1.copy/{$copyId}/item";
    }

    private function __construct()
    {
    }
}
