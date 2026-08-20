<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;

final class ClassificationNameNormalizer
{
    public function normalize(
        ClassificationTermName $name
    ): ClassificationNormalizedName {
        $caseFolded = mb_convert_case(
            $name->value(),
            MB_CASE_FOLD,
            "UTF-8"
        );
        $normalized = preg_replace(
            '/[\p{Z}\s\p{Pd}]+/u',
            ' ',
            $caseFolded
        );

        if ($normalized === null) {
            throw new ValidationException(
                "Classification term name could not be normalized."
            );
        }

        return new ClassificationNormalizedName(trim($normalized));
    }

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }
}
