<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Metadata\AddBookRecordIdGenerator;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;

final readonly class OpaqueAddBookRecordIdGenerator implements
    AddBookRecordIdGenerator
{
    public function nextItemId(): ItemId
    {
        return new ItemId("item-" . bin2hex(random_bytes(16)));
    }

    public function nextWorkId(): WorkId
    {
        return new WorkId("work-" . bin2hex(random_bytes(16)));
    }

    public function nextEditionId(): EditionId
    {
        return new EditionId("edition-" . bin2hex(random_bytes(16)));
    }
}
