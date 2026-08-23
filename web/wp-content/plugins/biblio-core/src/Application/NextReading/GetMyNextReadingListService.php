<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\NextReading\WritableNextReadingRepository;

final readonly class GetMyNextReadingListService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableNextReadingRepository $repository,
        private NextReadingProjector $projector
    ) {
    }

    public function get(): NextReadingListView
    {
        $actorId = $this->authenticatedUser->requireUserId();
        return $this->projector->project($this->repository->findForUser($actorId));
    }
}
