<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Collections\{CollectionMembershipId,CollectionMembershipIdGenerator};

final readonly class OpaqueCollectionMembershipIdGenerator implements CollectionMembershipIdGenerator
{
    public function next(): CollectionMembershipId { return new CollectionMembershipId('collection-membership-' . bin2hex(random_bytes(16))); }
}
