<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface OwnAssessmentReadRepository
{
    /** @return list<OwnAssessmentView> */
    public function notVisibleInLibraryForOwnerAndWork(
        UserId $ownerId,
        LibraryId $libraryId,
        WorkId $workId
    ): array;
}
