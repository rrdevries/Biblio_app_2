<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum ProviderHttpResultStatus: string
{
    case Response = "response";
    case Timeout = "timeout";
    case NetworkFailure = "network_failure";
}
