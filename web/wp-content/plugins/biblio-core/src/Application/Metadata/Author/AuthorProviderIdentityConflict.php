<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use RuntimeException;

final class AuthorProviderIdentityConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Provider Author identity is already claimed by another Author."
        );
    }
}
