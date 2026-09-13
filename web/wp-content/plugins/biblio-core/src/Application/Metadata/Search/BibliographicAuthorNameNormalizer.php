<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final class BibliographicAuthorNameNormalizer
{
    public static function normalize(string $value): string
    {
        if (!mb_check_encoding($value, "UTF-8")) {
            throw new ValidationException("Invalid bibliographic Author presentation name.");
        }
        $collapsed = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
        if (!is_string($collapsed)) {
            throw new ValidationException("Invalid bibliographic Author presentation name.");
        }
        return mb_convert_case(trim($collapsed), MB_CASE_FOLD, "UTF-8");
    }
}
