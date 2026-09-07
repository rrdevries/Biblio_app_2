<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Exception\ValidationException;

final readonly class WorkDiscoveryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WorkDiscoveryRepository $repository
    ) {
    }

    public function search(
        WorkDiscoverySearchTerm $search,
        ?WorkDiscoveryLimit $limit = null,
        ?WorkDiscoveryCursor $cursor = null
    ): WorkDiscoveryPage {
        $this->authenticatedUser->requireUserId();

        if ($cursor !== null && $cursor->search()->value() !== $search->value()) {
            throw new ValidationException(
                "Work discovery cursor does not match the search."
            );
        }

        return $this->repository->search(
            $search,
            $limit ?? new WorkDiscoveryLimit(),
            $cursor
        );
    }
}
