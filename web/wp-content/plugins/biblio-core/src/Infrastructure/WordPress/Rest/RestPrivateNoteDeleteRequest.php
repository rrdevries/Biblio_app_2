<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteVersion;

final readonly class RestPrivateNoteDeleteRequest
{
    public function __construct(
        private PrivateNoteId $privateNoteId,
        private PrivateNoteVersion $expectedVersion
    ) {
    }

    public function privateNoteId(): PrivateNoteId
    {
        return $this->privateNoteId;
    }

    public function expectedVersion(): PrivateNoteVersion
    {
        return $this->expectedVersion;
    }
}
