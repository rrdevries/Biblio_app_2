<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\PersonalReadingTruth;
use Biblio\Core\Reading\PersonalReadingTruthState;

final readonly class RecordPersonalReadingTruthService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PersonalReadingTruthRecorder $recorder,
        private TransactionManager $transactions
    ) {
    }

    public function record(
        WorkId $workId,
        PersonalReadingTruthState $state
    ): PersonalReadingTruth {
        $actorId = $this->authenticatedUser->requireUserId();

        return $this->transactions->run(
            fn (): PersonalReadingTruth => $this->recorder->recordForOwner(
                $actorId,
                $workId,
                $state
            )
        );
    }
}
