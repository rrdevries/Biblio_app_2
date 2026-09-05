<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataLookupStatus: string
{
    case Candidates = "candidates";
    case Ambiguous = "ambiguous";
    case NoUsableCandidate = "no_usable_candidate";
    case ProviderFailure = "provider_failure";
}
