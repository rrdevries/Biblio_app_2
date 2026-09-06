<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataFieldProposalState: string
{
    case Active = "active";
    case Supporting = "supporting";
    case Rejected = "rejected";
    case Superseded = "superseded";
    case Confirmed = "confirmed";
    case BlockedByIntentionalBlank = "blocked_by_intentional_blank";
}
