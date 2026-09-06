<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\EditionId;

interface EditionMetadataProvenanceRepository
{
    public function addReviewedCandidate(
        EditionId $editionId,
        MetadataCandidate $candidate,
        bool $corrected
    ): void;
}
