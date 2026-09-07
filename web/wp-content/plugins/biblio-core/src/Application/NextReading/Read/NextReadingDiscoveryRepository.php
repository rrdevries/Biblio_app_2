<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface NextReadingDiscoveryRepository
{
    /**
     * @param list<LibraryId> $libraryIds
     * @return list<NextReadingSourceOptionView>
     */
    public function libraryItemOptions(WorkId $workId, array $libraryIds): array;

    /** @return list<NextReadingSourceOptionView> */
    public function externalLoanOptions(UserId $actorId, WorkId $workId): array;
}
