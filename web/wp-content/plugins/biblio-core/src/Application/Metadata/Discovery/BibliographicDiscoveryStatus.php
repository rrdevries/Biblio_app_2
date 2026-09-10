<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

enum BibliographicDiscoveryStatus: string
{
    case Results = "results";
    case NoResults = "no_results";
    case ProviderFailure = "provider_failure";
    case ConfigurationFailure = "configuration_failure";
    case InvalidProviderResponse = "invalid_provider_response";
}
