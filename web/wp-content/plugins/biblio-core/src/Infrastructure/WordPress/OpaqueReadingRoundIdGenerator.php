<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundIdGenerator;

final readonly class OpaqueReadingRoundIdGenerator implements
    ReadingRoundIdGenerator
{
    public function next(): ReadingRoundId
    {
        return new ReadingRoundId("reading-round-" . bin2hex(random_bytes(16)));
    }
}
