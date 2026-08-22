<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundRepository;

final readonly class GetPersonalWorkReadingStatusService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ReadingRoundRepository $rounds
    ) {
    }

    public function get(WorkId $workId): PersonalWorkReadingStatus
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $rounds = $this->rounds->findAllForUserAndWork($actorId, $workId);

        foreach ($rounds as $round) {
            if ($round->lifecycle() === ReadingRoundLifecycle::Active) {
                return PersonalWorkReadingStatus::Reading;
            }
        }

        foreach ($rounds as $round) {
            if ($round->outcome() === ReadingRoundOutcome::Completed) {
                return PersonalWorkReadingStatus::Read;
            }
        }

        return PersonalWorkReadingStatus::NotRead;
    }
}
