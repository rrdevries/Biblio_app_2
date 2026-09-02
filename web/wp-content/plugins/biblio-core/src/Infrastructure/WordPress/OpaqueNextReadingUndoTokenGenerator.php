<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\NextReading\{NextReadingUndoToken,NextReadingUndoTokenGenerator};

final readonly class OpaqueNextReadingUndoTokenGenerator implements NextReadingUndoTokenGenerator
{
    public function next(): NextReadingUndoToken
    {
        return new NextReadingUndoToken("undo-" . bin2hex(random_bytes(24)));
    }
}
