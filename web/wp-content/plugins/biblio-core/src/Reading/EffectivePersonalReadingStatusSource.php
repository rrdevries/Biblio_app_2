<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum EffectivePersonalReadingStatusSource: string
{
    case ActiveReadingRound = "active_reading_round";
    case CompletedReadingRound = "completed_reading_round";
    case ReadingTruth = "reading_truth";
    case Default = "default";
}
