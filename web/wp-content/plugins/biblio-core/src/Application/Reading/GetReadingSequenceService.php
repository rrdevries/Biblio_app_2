<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundRepository;
use Biblio\Core\Reading\ReadingSequenceClassification;

final readonly class GetReadingSequenceService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ReadingRoundRepository $rounds
    ) {
    }

    /** @return list<ClassifiedReadingRound> */
    public function forWork(WorkId $workId): array
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $completed = array_values(array_filter(
            $this->rounds->findAllForUserAndWork($actorId, $workId),
            static fn (ReadingRound $round): bool =>
                $round->outcome() === ReadingRoundOutcome::Completed
        ));

        usort($completed, static function (ReadingRound $left, ReadingRound $right): int {
            $leftFinish = $left->period()->finishedOn();
            $rightFinish = $right->period()->finishedOn();

            if ($leftFinish === null || $rightFinish === null) {
                return $left->id()->value() <=> $right->id()->value();
            }

            return $leftFinish->earliest() <=> $rightFinish->earliest()
                ?: $leftFinish->latest() <=> $rightFinish->latest()
                ?: $left->id()->value() <=> $right->id()->value();
        });

        return array_map(
            fn (ReadingRound $round): ClassifiedReadingRound =>
                new ClassifiedReadingRound(
                    $round,
                    $this->classify($round, $completed)
                ),
            $completed
        );
    }

    /** @param list<ReadingRound> $completed */
    private function classify(
        ReadingRound $round,
        array $completed
    ): ReadingSequenceClassification {
        if (count($completed) === 1) {
            return ReadingSequenceClassification::FirstRead;
        }

        $finish = $round->period()->finishedOn();

        foreach ($completed as $other) {
            if ($other->id()->equals($round->id())) {
                continue;
            }

            $otherFinish = $other->period()->finishedOn();

            if (
                $finish !== null
                && $otherFinish !== null
                && $otherFinish->latest() < $finish->earliest()
            ) {
                return ReadingSequenceClassification::Reread;
            }
        }

        if ($finish !== null) {
            $earlierThanAll = true;

            foreach ($completed as $other) {
                if ($other->id()->equals($round->id())) {
                    continue;
                }

                $otherFinish = $other->period()->finishedOn();

                if ($otherFinish === null || !($finish->latest() < $otherFinish->earliest())) {
                    $earlierThanAll = false;
                    break;
                }
            }

            if ($earlierThanAll) {
                return ReadingSequenceClassification::FirstRead;
            }
        }

        return ReadingSequenceClassification::ChronologyIndeterminate;
    }
}
