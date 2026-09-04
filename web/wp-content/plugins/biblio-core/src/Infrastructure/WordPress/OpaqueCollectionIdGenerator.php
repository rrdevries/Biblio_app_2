<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Collections\{CollectionId,CollectionIdGenerator};

final readonly class OpaqueCollectionIdGenerator implements CollectionIdGenerator
{
    public function next(): CollectionId { return new CollectionId('collection-' . bin2hex(random_bytes(16))); }
}
