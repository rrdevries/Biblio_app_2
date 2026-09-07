<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

interface WorkDiscoveryRepository
{
    public function search(
        WorkDiscoverySearchTerm $search,
        WorkDiscoveryLimit $limit,
        ?WorkDiscoveryCursor $cursor
    ): WorkDiscoveryPage;
}
