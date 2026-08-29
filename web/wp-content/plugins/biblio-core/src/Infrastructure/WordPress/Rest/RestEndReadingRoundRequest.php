<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundVersion;

final readonly class RestEndReadingRoundRequest
{
    public function __construct(
        private ReadingRoundId $readingRoundId,
        private ReadingRoundOutcome $outcome,
        private ReadingDate $finishedOn,
        private ReadingRoundVersion $expectedVersion
    ) {
    }

    public function readingRoundId(): ReadingRoundId
    {
        return $this->readingRoundId;
    }

    public function outcome(): ReadingRoundOutcome
    {
        return $this->outcome;
    }

    public function finishedOn(): ReadingDate
    {
        return $this->finishedOn;
    }

    public function expectedVersion(): ReadingRoundVersion
    {
        return $this->expectedVersion;
    }
}
