<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

interface LibraryRepository
{
    public function add(Library $library): void;

    public function find(LibraryId $libraryId): ?Library;
}
