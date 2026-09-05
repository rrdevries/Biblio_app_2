<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;

interface MetadataProvider
{
    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult;
}
