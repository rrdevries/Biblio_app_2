<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteIdGenerator;

final readonly class OpaquePrivateNoteIdGenerator implements PrivateNoteIdGenerator
{
    public function next(): PrivateNoteId
    {
        return new PrivateNoteId("private-note-" . bin2hex(random_bytes(16)));
    }
}
