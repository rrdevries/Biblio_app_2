<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface ActorLibraryContextRepository
{
    public function findForActor(
        LibraryId $libraryId,
        UserId $actorId
    ): ?ActorLibraryContext;

    /** @return list<ActorLibraryContext> */
    public function listForActor(UserId $actorId): array;
}
