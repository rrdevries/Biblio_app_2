<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

final readonly class GetLibraryPublicAssessmentsService
{
    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private PublicAssessmentReadRepository $repository
    ) {
    }

    public function forWork(
        LibraryId $libraryId,
        WorkId $workId,
        ?PublicAssessmentCursor $cursor = null,
        ?PublicAssessmentPageSize $pageSize = null
    ): PublicAssessmentPage {
        $this->libraryContexts->get($libraryId);

        return $this->repository->forLibraryAndWork(
            $libraryId,
            $workId,
            $pageSize ?? new PublicAssessmentPageSize(),
            $cursor
        );
    }
}
