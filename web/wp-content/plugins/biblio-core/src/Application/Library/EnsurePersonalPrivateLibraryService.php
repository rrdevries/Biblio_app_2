<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

final readonly class EnsurePersonalPrivateLibraryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ProvisionPersonalPrivateLibraryService $provisioner
    ) {
    }

    public function ensure(): LibraryId
    {
        return $this->provisioner->provision(
            $this->authenticatedUser->requireUserId()
        );
    }
}
