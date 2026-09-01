<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes\Read;

use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class PrivateNoteViewCursor
{
    public function __construct(
        private DateTimeImmutable $beforeUpdatedAt,
        private PrivateNoteId $beforeId
    ) {
        PersistedDateTimeConstraints::assertSupported(
            $beforeUpdatedAt,
            "Private Note view cursor time"
        );
    }

    public function beforeUpdatedAt(): DateTimeImmutable
    {
        return $this->beforeUpdatedAt;
    }

    public function beforeId(): PrivateNoteId
    {
        return $this->beforeId;
    }
}
