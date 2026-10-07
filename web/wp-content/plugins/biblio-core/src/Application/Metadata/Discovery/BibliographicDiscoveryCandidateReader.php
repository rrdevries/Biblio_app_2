<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\Discovery;
use Biblio\Core\Application\Metadata\{MetadataLookupId,MetadataCandidateId};
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
interface BibliographicDiscoveryCandidateReader
{
    public function candidateForRead(MetadataLookupId $discoveryId, MetadataCandidateId $candidateId, UserId $actorId, DateTimeImmutable $at): ?BibliographicDiscoveryCandidate;
}
