<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkProviderPage
{
    /** @param array<mixed> $items */
    public function __construct(private array $items, private ?int $nextOffset)
    {
        if (!array_is_list($items)) {
            throw new ValidationException("Works-by-Author provider items must be a list.");
        }
        foreach ($items as $item) {
            if (!$item instanceof BibliographicWorkSearchResult) {
                throw new ValidationException("Works-by-Author provider page contains invalid data.");
            }
        }
        if ($nextOffset !== null && ($nextOffset < 1 || $nextOffset > 1000000)) {
            throw new ValidationException("Invalid Works-by-Author provider continuation.");
        }
    }

    /** @return list<BibliographicWorkSearchResult> */ public function items(): array { return $this->items; }
    public function nextOffset(): ?int { return $this->nextOffset; }
}
