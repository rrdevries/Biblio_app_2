<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableBibliographicMetadataRepository extends
    BibliographicMetadataRepository
{
    public function addAlternateTitle(AlternateWorkTitle $title): void;
    public function addContainment(WorkContainment $containment): void;
}
