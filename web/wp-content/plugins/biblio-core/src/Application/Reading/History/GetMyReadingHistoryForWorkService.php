<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;

final readonly class GetMyReadingHistoryForWorkService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ReadingHistoryReadRepository $repository
    ) {
    }

    public function forWork(
        WorkId $workId,
        ?ReadingHistoryCursor $cursor = null,
        ?ReadingHistoryPageSize $pageSize = null
    ): ReadingHistoryPage {
        return $this->repository->forUserAndWork(
            $this->authenticatedUser->requireUserId(),
            $workId,
            $pageSize ?? new ReadingHistoryPageSize(),
            $cursor
        );
    }
}
