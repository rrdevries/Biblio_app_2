<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

interface BibliographicDiscoverySnapshotRepository
{
    public function save(BibliographicDiscoverySnapshot $snapshot): void;

    public function candidateForMaterialization(
        MetadataLookupId $discoveryId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        DateTimeImmutable $at
    ): ?BibliographicDiscoveryCandidate;
}
