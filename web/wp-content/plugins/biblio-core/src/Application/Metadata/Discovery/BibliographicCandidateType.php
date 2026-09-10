<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

enum BibliographicCandidateType: string
{
    case LocalWork = "local_work";
    case LocalEdition = "local_edition";
    case ExternalWork = "external_work_candidate";
    case ExternalEdition = "external_edition_candidate";
}
