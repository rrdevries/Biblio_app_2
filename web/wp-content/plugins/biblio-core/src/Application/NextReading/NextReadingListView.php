<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\NextReading\NextReadingListVersion;

final readonly class NextReadingListView
{
    /** @param list<NextReadingEntryView> $entries */
    public function __construct(private NextReadingListVersion $version, private array $entries) {}
    public function version(): NextReadingListVersion { return $this->version; }
    /** @return list<NextReadingEntryView> */
    public function entries(): array { return $this->entries; }
}
