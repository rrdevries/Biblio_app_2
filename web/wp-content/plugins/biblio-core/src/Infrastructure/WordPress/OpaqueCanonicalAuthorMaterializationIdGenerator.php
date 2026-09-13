<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditId,
    CanonicalAuthorMaterializationIdGenerator
};
use Biblio\Core\Catalog\AuthorId;

final readonly class OpaqueCanonicalAuthorMaterializationIdGenerator implements
    CanonicalAuthorMaterializationIdGenerator
{
    public function nextAuthorId(): AuthorId
    {
        return new AuthorId("author-" . bin2hex(random_bytes(16)));
    }

    public function nextCreditId(): AuthorContributorCreditId
    {
        return new AuthorContributorCreditId(
            "author-credit-" . bin2hex(random_bytes(16))
        );
    }
}
