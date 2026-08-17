<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

interface WritableLibraryRepository extends LibraryRepository
{
    public function add(Library $library): void;
}
