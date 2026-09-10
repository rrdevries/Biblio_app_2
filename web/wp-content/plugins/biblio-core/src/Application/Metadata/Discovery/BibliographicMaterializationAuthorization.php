<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Identity\UserId;

interface BibliographicMaterializationAuthorization
{
    public function assertAllowed(UserId $actorId): void;
}
