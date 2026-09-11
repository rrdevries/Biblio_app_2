<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\WorkId;

interface BibliographicAuthorWorkMappingLookup
{
    /**
     * @param list<string> $providerWorkRecordIds
     * @return array<string,WorkId> keyed by provider Work record ID
     */
    public function mappedWorksForAuthor(
        AuthorId $authorId,
        string $providerKey,
        array $providerWorkRecordIds
    ): array;
}
