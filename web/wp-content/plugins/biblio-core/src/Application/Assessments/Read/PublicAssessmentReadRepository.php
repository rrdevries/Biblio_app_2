<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

interface PublicAssessmentReadRepository
{
    public function forLibraryAndWork(
        LibraryId $libraryId,
        WorkId $workId,
        PublicAssessmentPageSize $pageSize,
        ?PublicAssessmentCursor $cursor
    ): PublicAssessmentPage;
}
