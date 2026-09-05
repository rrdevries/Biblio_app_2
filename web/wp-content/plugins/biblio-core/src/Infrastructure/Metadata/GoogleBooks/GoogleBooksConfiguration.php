<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\GoogleBooks;

use InvalidArgumentException;

final readonly class GoogleBooksConfiguration
{
    public function __construct(private ?string $apiKey)
    {
        if (
            $apiKey !== null
            && (
                $apiKey === ""
                || trim($apiKey) !== $apiKey
                || strlen($apiKey) > 256
                || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1
            )
        ) {
            throw new InvalidArgumentException("Google Books API configuration is invalid.");
        }
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }
}
