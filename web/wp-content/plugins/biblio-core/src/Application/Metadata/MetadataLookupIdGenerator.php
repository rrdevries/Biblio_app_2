<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

interface MetadataLookupIdGenerator
{
    public function next(): MetadataLookupId;
}
