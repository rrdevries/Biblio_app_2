<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundOutcome;

final readonly class ReadingHistoryEntry
{
    public function __construct(
        private ReadingRoundOutcome $outcome,
        private ?ReadingDate $startedOn,
        private ReadingDate $finishedOn,
        private ReadingHistorySourceType $sourceType,
        private bool $historicalRegistration
    ) {
    }

    public function outcome(): ReadingRoundOutcome
    {
        return $this->outcome;
    }

    public function startedOn(): ?ReadingDate
    {
        return $this->startedOn;
    }

    public function finishedOn(): ReadingDate
    {
        return $this->finishedOn;
    }

    public function sourceType(): ReadingHistorySourceType
    {
        return $this->sourceType;
    }

    public function historicalRegistration(): bool
    {
        return $this->historicalRegistration;
    }
}
