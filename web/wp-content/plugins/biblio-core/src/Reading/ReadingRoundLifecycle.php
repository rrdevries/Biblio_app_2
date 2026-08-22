<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum ReadingRoundLifecycle: string
{
    case Active = "active";
    case Ended = "ended";
}
