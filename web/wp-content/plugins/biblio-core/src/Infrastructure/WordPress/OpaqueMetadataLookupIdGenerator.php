<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupIdGenerator;

final readonly class OpaqueMetadataLookupIdGenerator implements
    MetadataLookupIdGenerator
{
    public function next(): MetadataLookupId
    {
        return new MetadataLookupId("lookup-" . bin2hex(random_bytes(16)));
    }
}
