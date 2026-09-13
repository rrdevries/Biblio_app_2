<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\AuthorId;

interface BibliographicAuthorProviderIdentityLookup
{
    /**
     * @param list<string> $providerAuthorRecordIds
     * @return array<string,AuthorId> keyed by provider Author record ID
     */
    public function mappedAuthors(string $providerKey, array $providerAuthorRecordIds): array;

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string,list<BibliographicProviderEntityIdentity>> keyed by canonical Author ID
     */
    public function providerAuthorIdentities(string $providerKey, array $authorIds): array;
}
