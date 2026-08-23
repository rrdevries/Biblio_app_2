<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface CatalogUiReadRepository
{
    public function activeOverview(
        LibraryId $libraryId,
        UserId $actorId,
        CatalogOverviewPageSize $pageSize,
        ?CatalogOverviewCursor $cursor
    ): CatalogItemReadRecordPage;

    public function activeDetail(
        LibraryId $libraryId,
        ItemId $itemId,
        UserId $actorId
    ): ?CatalogItemReadRecord;
}
