<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;

final readonly class GetOwnAssessmentsForWorkService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryContextQueryService $libraryContexts,
        private OwnAssessmentReadRepository $repository
    ) {
    }

    /** @return list<OwnAssessmentView> */
    public function notVisibleInLibrary(
        LibraryId $libraryId,
        WorkId $workId
    ): array {
        $this->libraryContexts->get($libraryId);

        return $this->repository->notVisibleInLibraryForOwnerAndWork(
            $this->authenticatedUser->requireUserId(),
            $libraryId,
            $workId
        );
    }
}
