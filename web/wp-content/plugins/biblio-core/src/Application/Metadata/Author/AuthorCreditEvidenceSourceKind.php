<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorCreditEvidenceSourceKind: string
{
    case Provider = "provider";
    case UserObservation = "user_observation";
    case Migration = "migration";
}
