<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface WritableAuthorRepository extends AuthorRepository
{
    public function add(Author $author): void;
    public function replaceIfVersionMatches(
        Author $replacement,
        AuthorVersion $expectedVersion
    ): bool;
    public function addContributor(WorkContributor $contributor): void;
}
