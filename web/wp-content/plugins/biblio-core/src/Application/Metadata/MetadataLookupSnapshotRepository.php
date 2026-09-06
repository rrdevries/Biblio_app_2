<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;

interface MetadataLookupSnapshotRepository
{
    public function save(MetadataLookupSnapshot $snapshot): void;

    public function candidateForCommit(
        MetadataLookupId $lookupId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        LibraryId $libraryId,
        DateTimeImmutable $at
    ): ?MetadataCandidate;
}
