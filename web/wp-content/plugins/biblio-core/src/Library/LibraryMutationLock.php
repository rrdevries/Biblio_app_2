<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

interface LibraryMutationLock
{
    /** Must be called inside the owning application transaction. */
    public function acquire(LibraryId $libraryId): void;
}
