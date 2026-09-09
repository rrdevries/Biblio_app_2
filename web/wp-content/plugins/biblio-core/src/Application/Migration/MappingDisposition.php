<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

enum MappingDisposition: string
{
    case Created = "created";
    case Reused = "reused";
}
