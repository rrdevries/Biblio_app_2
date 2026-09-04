<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\PersonalWorkReadingStatusSource;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundOutcome;

final readonly class GetPersonalWorkReadingStatusService
{
    public const MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PersonalWorkReadingStatusSource $rounds
    ) {
    }

    public function get(WorkId $workId): PersonalWorkReadingStatus
    {
        return $this->getMany([$workId])[$workId->value()];
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, PersonalWorkReadingStatus>
     */
    public function getMany(array $workIds): array
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $this->assertWorkBatch($workIds);
        $roundsByWork = $this->rounds->findAllForUserAndWorks($actorId, $workIds);
        $result = [];

        foreach ($workIds as $workId) {
            $result[$workId->value()] = $this->derive($roundsByWork[$workId->value()] ?? []);
        }

        return $result;
    }

    /** @param list<\Biblio\Core\Reading\ReadingRound> $rounds */
    private function derive(array $rounds): PersonalWorkReadingStatus
    {
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

    /** @param array<mixed> $workIds */
    private function assertWorkBatch(array $workIds): void
    {
        if (count($workIds) > self::MAXIMUM_BATCH_SIZE) {
            throw new ValidationException('Personal reading-status batches may contain at most 100 Works.');
        }
        foreach ($workIds as $workId) {
            if (!$workId instanceof WorkId) {
                throw new ValidationException('Personal reading-status batches must contain only Work IDs.');
            }
        }
    }
}
