<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\AuthorId;

interface AuthorProviderIdentityRepository
{
    public function findAuthor(string $provider, string $recordId): ?AuthorId;
    public function claimAuthor(
        string $provider,
        string $recordId,
        AuthorId $authorId
    ): void;
}
