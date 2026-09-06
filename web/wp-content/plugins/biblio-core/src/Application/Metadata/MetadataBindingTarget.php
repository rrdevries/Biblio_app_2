<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataBindingTarget: string
{
    case EditionTitleEvidence = "edition_title_evidence";
    case Edition = "edition";
    case Work = "work";
    case EditionBinding = "edition_binding";
    case EvidenceOnly = "evidence_only";
}
