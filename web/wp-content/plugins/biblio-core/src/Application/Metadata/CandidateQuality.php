<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum CandidateQuality: string
{
    case Invalid = "invalid";
    case Incomplete = "incomplete";
    case Sufficient = "sufficient";
}
