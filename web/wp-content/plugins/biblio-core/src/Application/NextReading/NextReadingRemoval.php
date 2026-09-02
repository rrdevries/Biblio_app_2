<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\NextReading\{NextReadingList,NextReadingUndoToken};
use DateTimeImmutable;

final readonly class NextReadingRemoval
{
    public function __construct(
        private NextReadingList $list,
        private NextReadingUndoToken $undoToken,
        private DateTimeImmutable $undoExpiresAt
    ) {
    }

    public function list(): NextReadingList { return $this->list; }
    public function undoToken(): NextReadingUndoToken { return $this->undoToken; }
    public function undoExpiresAt(): DateTimeImmutable { return $this->undoExpiresAt; }
}
