<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

interface UserObservedMetadataEvidenceRepository
{
    public function add(UserObservedMetadataEvidence $evidence): void;
}
