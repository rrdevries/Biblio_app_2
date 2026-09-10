<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

enum BibliographicMaterializationIntent: string
{
    case WorkOnly = "work_only";
    case WorkAndEdition = "work_and_edition";
}
