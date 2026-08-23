<?php
declare(strict_types=1);
namespace Biblio\Core\Infrastructure\WordPress;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingEntryIdGenerator};
final readonly class OpaqueNextReadingEntryIdGenerator implements NextReadingEntryIdGenerator
{
    public function next(): NextReadingEntryId
    {
        return new NextReadingEntryId("next-" . bin2hex(random_bytes(16)));
    }
}
