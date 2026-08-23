<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Reading\PersonalWorkReadingStatus;

final readonly class CatalogReadingSummary
{
    public function __construct(
        private PersonalWorkReadingStatus $status,
        private int $activeRounds,
        private int $completedRounds,
        private int $stoppedRounds,
        private int $historicalCompletedRounds
    ) {
    }

    public function status(): PersonalWorkReadingStatus { return $this->status; }
    public function activeRounds(): int { return $this->activeRounds; }
    public function completedRounds(): int { return $this->completedRounds; }
    public function stoppedRounds(): int { return $this->stoppedRounds; }
    public function historicalCompletedRounds(): int
    {
        return $this->historicalCompletedRounds;
    }
}
