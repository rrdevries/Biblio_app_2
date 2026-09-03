<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableAuthorRepository extends AuthorRepository
{
    public function save(Author $author): void;
    public function addContributor(WorkContributor $contributor): void;
}
