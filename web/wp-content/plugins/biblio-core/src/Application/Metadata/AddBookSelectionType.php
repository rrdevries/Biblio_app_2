<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum AddBookSelectionType: string
{
    case Manual = "manual";
    case Candidate = "candidate";
    case ExistingEdition = "existing_edition";
}
