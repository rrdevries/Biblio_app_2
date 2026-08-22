<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;

final readonly class StopReadingRoundService
{
    public function __construct(private ReadingRoundEnd $end)
    {
    }

    public function stop(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingDate $finishedOn
    ): ReadingRound {
        return $this->end->stopped($id, $expectedVersion, $finishedOn);
    }
}
