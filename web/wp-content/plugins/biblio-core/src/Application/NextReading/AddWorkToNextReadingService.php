<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\{WorkId,WorkRepository};
use Biblio\Core\NextReading\{NextReadingList,NextReadingTarget,NextReadingTargetUnavailable};

final readonly class AddWorkToNextReadingService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkRepository $works,
        private NextReadingMutation $mutation
    ) {
    }

    public function add(WorkId $workId): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        if ($this->works->find($workId) === null) {
            throw new NextReadingTargetUnavailable();
        }
        return $this->mutation->append($actorId, NextReadingTarget::forWork($workId));
    }
}
