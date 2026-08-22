<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;

final readonly class FinishReadingRoundService
{
    public function __construct(private ReadingRoundEnd $end)
    {
    }

    public function finish(
        ReadingRoundId $id,
        ReadingRoundVersion $expectedVersion,
        ReadingDate $finishedOn
    ): ReadingRound {
        return $this->end->completed($id, $expectedVersion, $finishedOn);
    }
}
