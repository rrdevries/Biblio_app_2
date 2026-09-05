<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum ProviderLookupStatus: string
{
    case Candidates = "candidates";
    case Miss = "miss";
    case Unavailable = "unavailable";
    case RateLimited = "rate_limited";
    case ConfigurationError = "configuration_error";
    case InvalidResponse = "invalid_response";
}
