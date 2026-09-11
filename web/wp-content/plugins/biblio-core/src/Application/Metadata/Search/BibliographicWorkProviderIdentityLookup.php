<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\WorkId;

interface BibliographicWorkProviderIdentityLookup
{
    /** @return list<BibliographicProviderEntityIdentity> */
    public function providerWorkIdentities(WorkId $workId, string $providerKey): array;
}
