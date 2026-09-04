<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ValidationException;

final readonly class CollectionNameNormalizer
{
    public function normalize(CollectionName $name): NormalizedCollectionName
    {
        $folded = mb_convert_case($name->value(), MB_CASE_FOLD, 'UTF-8');
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $folded);
        if ($normalized === null) {
            throw new ValidationException("Collection name could not be normalized.");
        }
        return new NormalizedCollectionName(trim($normalized));
    }
}
