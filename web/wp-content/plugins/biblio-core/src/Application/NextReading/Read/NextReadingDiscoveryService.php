<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading\Read;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\{WorkId,WorkRepository};
use Biblio\Core\NextReading\NextReadingWorkUnavailable;

final readonly class NextReadingDiscoveryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkRepository $works,
        private LibraryContextQueryService $libraryContexts,
        private NextReadingDiscoveryRepository $repository
    ) {
    }

    public function searchWorks(
        NextReadingWorkSearchTerm $search,
        ?NextReadingDiscoveryLimit $limit = null,
        ?NextReadingWorkCursor $cursor = null
    ): NextReadingWorkPage {
        $this->authenticatedUser->requireUserId();

        if ($cursor !== null && $cursor->search()->value() !== $search->value()) {
            throw new \Biblio\Core\Exception\ValidationException(
                "Work discovery cursor does not match the search."
            );
        }

        return $this->repository->searchWorks(
            $search,
            $limit ?? new NextReadingDiscoveryLimit(),
            $cursor
        );
    }

    /** @return list<NextReadingSourceOptionView> */
    public function sourceOptions(WorkId $workId): array
    {
        $actorId = $this->authenticatedUser->requireUserId();

        if ($this->works->find($workId) === null) {
            throw new NextReadingWorkUnavailable();
        }

        $libraryIds = array_map(
            static fn ($library) => $library->libraryId(),
            $this->libraryContexts->myLibraries()
        );

        return [
            ...$this->repository->libraryItemOptions($workId, $libraryIds),
            ...$this->repository->externalLoanOptions($actorId, $workId),
        ];
    }
}
