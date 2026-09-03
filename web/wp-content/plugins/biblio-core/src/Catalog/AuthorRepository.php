<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface AuthorRepository
{
    public function find(AuthorId $authorId): ?Author;

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, Author>
     */
    public function findMany(array $authorIds): array;

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<WorkContributor>>
     */
    public function contributorsForWorks(array $workIds): array;

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, list<WorkId>>
     */
    public function workIdsForAuthors(array $authorIds): array;
}
