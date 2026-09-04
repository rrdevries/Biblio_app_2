<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;

interface CollectionMembershipArchivePort
{
    public function deactivateForArchivedItem(LibraryId $libraryId, ItemId $itemId, DateTimeImmutable $archivedAt): void;
}
