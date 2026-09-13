<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Exception\ValidationException;

final readonly class OpenLibraryAuthorId
{
    public const string PROVIDER_KEY = "open_library";

    private string $value;

    public function __construct(string $value)
    {
        $canonical = str_starts_with($value, "/authors/")
            ? $value : "/authors/" . $value;
        if (preg_match('#^/authors/OL[0-9]+A$#D', $canonical) !== 1) {
            throw new ValidationException(
                "Open Library Author ID must be a canonical Author key."
            );
        }

        $this->value = $canonical;
    }

    public function value(): string
    {
        return $this->value;
    }
}
