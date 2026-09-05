<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class IsbnCanonicalizer
{
    public function parse(string $input): IsbnParseResult
    {
        $normalized = IsbnRules::normalized($input);

        try {
            $isbn = strlen($normalized) === 10
                ? new Isbn10($normalized)
                : new Isbn13($normalized);

            return IsbnParseResult::valid(
                CanonicalIsbnIdentity::fromIsbn($isbn)
            );
        } catch (InvalidIsbnInput $exception) {
            return IsbnParseResult::invalid($exception->inputError());
        }
    }
}
