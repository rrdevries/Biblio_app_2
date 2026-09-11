<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicEditionSearchPage
{
    /**
     * @param array<mixed> $items
     * @param array<mixed> $providerAttempts
     */
    public function __construct(
        private BibliographicWorkReference $work,
        private array $items,
        private ?BibliographicEditionSearchCursor $nextCursor,
        private array $providerAttempts = []
    ) {
        if (!array_is_list($items) || count($items) > BibliographicEditionSearchService::PAGE_SIZE) {
            throw new ValidationException("Edition search page items must be a bounded list.");
        }
        $previous = null;
        $seen = [];
        foreach ($items as $item) {
            if (!$item instanceof BibliographicEditionSearchResult
                || $item->parentWork()->cursorContextId() !== $work->cursorContextId()) {
                throw new ValidationException("Edition search page contains invalid Work data.");
            }
            $key = $item->sortKey();
            if ($previous !== null && $previous >= $key) {
                throw new ValidationException("Edition search page order is invalid.");
            }
            foreach ($item->strongIdentityKeys() as $identity) {
                if (isset($seen[$identity])) {
                    throw new ValidationException("Edition search page contains a strong-identity duplicate.");
                }
                $seen[$identity] = true;
            }
            $previous = $key;
        }
        if ($nextCursor !== null && $nextCursor->workContextId() !== $work->cursorContextId()) {
            throw new ValidationException("Edition continuation does not match the selected Work.");
        }
        if (!array_is_list($providerAttempts)) {
            throw new ValidationException("Edition provider attempts must be a list.");
        }
        foreach ($providerAttempts as $attempt) {
            if (!$attempt instanceof BibliographicSearchProviderAttempt) {
                throw new ValidationException("Edition provider attempts contain invalid data.");
            }
        }
    }

    public function work(): BibliographicWorkReference { return $this->work; }
    /** @return list<BibliographicEditionSearchResult> */ public function items(): array { return $this->items; }
    public function nextCursor(): ?BibliographicEditionSearchCursor { return $this->nextCursor; }
    /** @return list<BibliographicSearchProviderAttempt> */
    public function providerAttempts(): array { return $this->providerAttempts; }
}
