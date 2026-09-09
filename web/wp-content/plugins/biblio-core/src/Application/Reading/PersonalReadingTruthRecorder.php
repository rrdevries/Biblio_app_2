<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\PersonalReadingTruth;
use Biblio\Core\Reading\PersonalReadingTruthClock;
use Biblio\Core\Reading\PersonalReadingTruthContradiction;
use Biblio\Core\Reading\PersonalReadingTruthStale;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\PersonalWorkReadingMutationLock;
use Biblio\Core\Reading\PersonalReadingTruthRoundEvidence;
use Biblio\Core\Reading\WritablePersonalReadingTruthRepository;

/**
 * Source-neutral participant for an existing transaction.
 *
 * Self-service callers use RecordPersonalReadingTruthService. Migration
 * orchestration may compose this participant inside the MIG-FND transaction.
 */
final readonly class PersonalReadingTruthRecorder
{
    public function __construct(
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private PersonalReadingTruthRoundEvidence $roundEvidence,
        private WritablePersonalReadingTruthRepository $truths,
        private PersonalWorkReadingMutationLock $lock,
        private PersonalReadingTruthClock $clock
    ) {
    }

    public function recordForOwner(
        UserId $userId,
        WorkId $workId,
        PersonalReadingTruthState $state
    ): PersonalReadingTruth {
        if (!$this->users->isActive($userId)) {
            throw new ValidationException(
                "Personal Reading Truth requires an active user."
            );
        }
        if ($this->works->find($workId) === null) {
            throw new ValidationException(
                "Personal Reading Truth requires an existing Work."
            );
        }

        $this->lock->acquire($userId, $workId);
        $current = $this->truths->findForUserAndWorkForUpdate($userId, $workId);

        if ($state === PersonalReadingTruthState::ExplicitNotRead) {
            if ($this->roundEvidence->hasCompletedForUserAndWorkForUpdate(
                $userId,
                $workId
            )) {
                throw new PersonalReadingTruthContradiction();
            }
        }

        if ($current === null) {
            $created = PersonalReadingTruth::record(
                $userId,
                $workId,
                $state,
                $this->clock->now()
            );
            $this->truths->add($created);

            return $created;
        }

        $replacement = $current->replace($state, $this->clock->now());
        if ($replacement === $current) {
            return $current;
        }
        if (!$this->truths->replaceIfVersionMatches(
            $replacement,
            $current->version()
        )) {
            throw new PersonalReadingTruthStale();
        }

        return $replacement;
    }
}
