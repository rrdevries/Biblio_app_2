<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Wishlist\WishlistWriteResult;

final readonly class RefineWishlistEntryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WishlistRecorder $recorder,
        private TransactionManager $transactions
    ) {
    }

    public function refineToEdition(
        WorkId $workId,
        EditionId $editionId
    ): WishlistWriteResult {
        $owner = $this->authenticatedUser->requireUserId();
        return $this->transactions->run(
            fn (): WishlistWriteResult => $this->recorder
                ->refineWorkOnlyForOwner($owner, $workId, $editionId)
        );
    }
}
