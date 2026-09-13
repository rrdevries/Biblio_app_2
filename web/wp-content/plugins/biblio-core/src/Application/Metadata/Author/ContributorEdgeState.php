<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum ContributorEdgeState
{
    case Absent;
    case Exact;
    case Conflict;
}
