<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

interface LibraryRepository
{
    public function find(LibraryId $libraryId): ?Library;
}
