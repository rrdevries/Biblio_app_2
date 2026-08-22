<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum ReadingRoundOutcome: string
{
    case Completed = "completed";
    case Stopped = "stopped";
}
