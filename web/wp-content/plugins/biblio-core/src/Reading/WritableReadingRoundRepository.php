<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Identity\UserId;

interface WritableReadingRoundRepository extends ReadingRoundRepository
{
    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void;

    public function replaceIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRound $replacement,
        ReadingRoundVersion $expectedVersion,
        ReadingRoundLifecycle $expectedLifecycle
    ): bool;

    public function deleteHistoricalIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRoundId $readingRoundId,
        ReadingRoundVersion $expectedVersion
    ): bool;
}
