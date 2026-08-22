<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingSequenceClassification;

final readonly class ClassifiedReadingRound
{
    public function __construct(
        private ReadingRound $round,
        private ReadingSequenceClassification $classification
    ) {
    }

    public function round(): ReadingRound { return $this->round; }
    public function classification(): ReadingSequenceClassification
    {
        return $this->classification;
    }
}
