<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataFieldConfirmationState: string
{
    case Unknown = "unknown";
    case Unconfirmed = "unconfirmed";
    case UserConfirmed = "user_confirmed";
    case IntentionallyBlank = "intentionally_blank";
}
