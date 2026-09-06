<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;

interface AddBookRecordIdGenerator
{
    public function nextItemId(): ItemId;
    public function nextWorkId(): WorkId;
    public function nextEditionId(): EditionId;
}
