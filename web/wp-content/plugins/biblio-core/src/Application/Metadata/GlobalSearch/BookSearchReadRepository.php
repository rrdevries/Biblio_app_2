<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;

interface BookSearchReadRepository
{
    public function work(string $id): ?array;
    public function edition(string $id): ?array;
    /** Each read must be explicitly constrained to these server-authorized libraries. */
    public function registrations(string $scope, string $id, array $libraryIds, ?array $after, int $limit): array;
    /** One batch for the visible page; contains only result IDs and presence booleans. */
    public function presence(array $identities, array $libraryIds): array;
    public function descriptionSource(string $scope, string $id): ?array;
}
