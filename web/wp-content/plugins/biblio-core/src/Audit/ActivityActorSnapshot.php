<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\UserId;

final readonly class ActivityActorSnapshot
{
    public function __construct(
        private ?UserId $userId,
        private ActivityLabel $displayName
    ) {
    }

    public function userId(): ?UserId
    {
        return $this->userId;
    }

    public function displayName(): ActivityLabel
    {
        return $this->displayName;
    }
}
