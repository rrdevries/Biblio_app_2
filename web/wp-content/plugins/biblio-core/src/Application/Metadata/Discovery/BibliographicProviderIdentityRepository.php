<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;

interface BibliographicProviderIdentityRepository
{
    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId;
    public function findEdition(string $provider, string $recordId): ?EditionId;
    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void;
    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void;
}
