<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;

interface CatalogMigrationRecordIdGenerator
{
    public function nextWorkId(): WorkId;
    public function nextEditionId(): EditionId;
    public function nextItemId(): ItemId;
}
