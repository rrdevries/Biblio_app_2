<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface ActivityEventFactory
{
    /**
     * @param list<ActivityEntitySnapshot> $relatedEntities
     * @param list<ActivityChange> $changes
     */
    public function create(
        UserId $actorId,
        LibraryId $libraryId,
        ActivityEventKey $eventKey,
        ActivityEntityIdentity $primaryEntity,
        array $relatedEntities,
        array $changes
    ): ActivityEvent;
}
