<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

interface ReadingHistoryReadRepository
{
    public function forUserAndWork(
        UserId $userId,
        WorkId $workId,
        ReadingHistoryPageSize $pageSize,
        ?ReadingHistoryCursor $cursor
    ): ReadingHistoryPage;
}
