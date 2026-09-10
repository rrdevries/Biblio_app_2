<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

interface BibliographicLocalDiscoveryRepository
{
    /** @return list<BibliographicDiscoveryCandidate> */
    public function searchText(BibliographicDiscoveryQuery $query, int $limit = 20): array;
}
