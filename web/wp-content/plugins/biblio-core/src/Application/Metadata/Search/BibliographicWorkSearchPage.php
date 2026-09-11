<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicWorkSearchPage
{
    /** @var list<BibliographicWorkSearchResult> */
    private array $items;

    /** @param array<mixed> $items */
    public function __construct(
        private BibliographicTextSearchQuery $query,
        array $items,
        private ?BibliographicSearchCursor $nextCursor
    ) {
        $previous = null;
        $identities = [];
        if (!array_is_list($items)) {
            throw new ValidationException("Bibliographic Work results must be a list.");
        }
        foreach ($items as $item) {
            if (!$item instanceof BibliographicWorkSearchResult) {
                throw new ValidationException("Bibliographic Work page contains invalid data.");
            }
            foreach ($item->reference()->strongIdentityKeys() as $identity) {
                if (isset($identities[$identity])) {
                    throw new ValidationException("Duplicate strong Work identity in search page.");
                }
                $identities[$identity] = true;
            }
            if ($previous !== null && $item->sortKey() <= $previous) {
                throw new ValidationException("Bibliographic Work results are not deterministically ordered.");
            }
            $previous = $item->sortKey();
        }
        /** @var list<BibliographicWorkSearchResult> $items */
        $this->items = $items;
        $this->assertCursor($nextCursor);
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    /** @return list<BibliographicWorkSearchResult> */ public function items(): array { return $this->items; }
    public function nextCursor(): ?BibliographicSearchCursor { return $this->nextCursor; }

    private function assertCursor(?BibliographicSearchCursor $cursor): void
    {
        if ($cursor === null) { return; }
        $last = $this->items === [] ? null : $this->items[array_key_last($this->items)];
        if ($last === null || $cursor->group() !== BibliographicSearchGroup::Works
            || $cursor->query()->value() !== $this->query->value()
            || $cursor->sortKey() !== $last->sortKey()) {
            throw new ValidationException("Invalid next cursor for bibliographic Work page.");
        }
    }
}
