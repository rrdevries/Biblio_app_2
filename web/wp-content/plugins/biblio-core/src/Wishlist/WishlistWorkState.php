<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;

final readonly class WishlistWorkState
{
    public function __construct(
        private UserId $ownerUserId,
        private WorkId $workId,
        private WishlistTargetType $targetType
    ) {
    }

    public function ownerUserId(): UserId { return $this->ownerUserId; }
    public function workId(): WorkId { return $this->workId; }
    public function targetType(): WishlistTargetType { return $this->targetType; }
}
