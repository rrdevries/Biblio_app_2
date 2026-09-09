<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\PersonalWorkReadingStatusSource;
use Biblio\Core\Reading\EffectivePersonalReadingStatus;
use Biblio\Core\Reading\EffectivePersonalReadingStatusSource;
use Biblio\Core\Reading\PersonalReadingTruth;
use Biblio\Core\Reading\PersonalReadingTruthRepository;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundOutcome;

final readonly class GetPersonalWorkReadingStatusService
{
    public const MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PersonalWorkReadingStatusSource $rounds,
        private ?PersonalReadingTruthRepository $truths = null
    ) {
    }

    public function get(WorkId $workId): PersonalWorkReadingStatus
    {
        return $this->getDetails($workId)->status();
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, PersonalWorkReadingStatus>
     */
    public function getMany(array $workIds): array
    {
        return array_map(
            static fn (EffectivePersonalReadingStatus $status): PersonalWorkReadingStatus =>
                $status->status(),
            $this->getManyDetails($workIds)
        );
    }

    public function getDetails(WorkId $workId): EffectivePersonalReadingStatus
    {
        return $this->getManyDetails([$workId])[$workId->value()];
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, EffectivePersonalReadingStatus>
     */
    public function getManyDetails(array $workIds): array
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $this->assertWorkBatch($workIds);
        $roundsByWork = $this->rounds->findAllForUserAndWorks($actorId, $workIds);
        $truthsByWork = $this->truths?->findAllForUserAndWorks(
            $actorId,
            $workIds
        ) ?? [];
        $result = [];

        foreach ($workIds as $workId) {
            $result[$workId->value()] = $this->derive(
                $roundsByWork[$workId->value()] ?? [],
                $truthsByWork[$workId->value()] ?? null
            );
        }

        return $result;
    }

    /** @param list<\Biblio\Core\Reading\ReadingRound> $rounds */
    private function derive(
        array $rounds,
        ?PersonalReadingTruth $truth
    ): EffectivePersonalReadingStatus
    {
        $hasCompleted = false;
        foreach ($rounds as $round) {
            if ($round->lifecycle() === ReadingRoundLifecycle::Active) {
                foreach ($rounds as $candidate) {
                    if ($candidate->outcome() === ReadingRoundOutcome::Completed) {
                        $hasCompleted = true;
                        break;
                    }
                }
                return new EffectivePersonalReadingStatus(
                    PersonalWorkReadingStatus::Reading,
                    EffectivePersonalReadingStatusSource::ActiveReadingRound,
                    $truth?->state(),
                    $hasCompleted
                        || $truth?->state() === PersonalReadingTruthState::ReadKnownDateUnknown,
                    null
                );
            }
        }
        foreach ($rounds as $round) {
            if ($round->outcome() === ReadingRoundOutcome::Completed) {
                return new EffectivePersonalReadingStatus(
                    PersonalWorkReadingStatus::Read,
                    EffectivePersonalReadingStatusSource::CompletedReadingRound,
                    $truth?->state(),
                    true,
                    true
                );
            }
        }

        return match ($truth?->state()) {
            PersonalReadingTruthState::ReadKnownDateUnknown =>
                new EffectivePersonalReadingStatus(
                    PersonalWorkReadingStatus::Read,
                    EffectivePersonalReadingStatusSource::ReadingTruth,
                    $truth->state(),
                    true,
                    false
                ),
            PersonalReadingTruthState::ExplicitNotRead =>
                new EffectivePersonalReadingStatus(
                    PersonalWorkReadingStatus::NotRead,
                    EffectivePersonalReadingStatusSource::ReadingTruth,
                    $truth->state(),
                    false,
                    null
                ),
            PersonalReadingTruthState::Unknown =>
                new EffectivePersonalReadingStatus(
                    PersonalWorkReadingStatus::Unknown,
                    EffectivePersonalReadingStatusSource::ReadingTruth,
                    $truth->state(),
                    false,
                    null
                ),
            null => new EffectivePersonalReadingStatus(
                PersonalWorkReadingStatus::NotRead,
                EffectivePersonalReadingStatusSource::Default,
                null,
                false,
                null
            ),
        };
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
