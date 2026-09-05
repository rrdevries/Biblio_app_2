<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface EditionIdentifierClaimRepository
{
    public function findByCanonicalIsbn13(Isbn13 $isbn13): ?EditionId;

    public function claim(Isbn13 $isbn13, EditionId $editionId): void;
}
