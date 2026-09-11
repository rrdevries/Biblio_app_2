<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkSearchPage
{
    /**
     * @param array<mixed> $items
     * @param array<mixed> $providerAttempts
     */
    public function __construct(
        private BibliographicAuthorReference $author,
        private array $items,
        private ?BibliographicAuthorWorkSearchCursor $nextCursor,
        private array $providerAttempts = []
    ) {
        if (!array_is_list($items) || count($items) > BibliographicAuthorWorkSearchService::PAGE_SIZE) {
            throw new ValidationException("Works-by-Author items must be a bounded list.");
        }
        $previous = null;
        $seen = [];
        foreach ($items as $item) {
            if (!$item instanceof BibliographicWorkSearchResult) {
                throw new ValidationException("Works-by-Author page contains invalid Work data.");
            }
            $key = $item->sortKey();
            if ($previous !== null && $previous >= $key) {
                throw new ValidationException("Works-by-Author page order is invalid.");
            }
            foreach ($item->reference()->strongIdentityKeys() as $identity) {
                if (isset($seen[$identity])) {
                    throw new ValidationException("Works-by-Author page contains a strong-identity duplicate.");
                }
                $seen[$identity] = true;
            }
            $previous = $key;
        }
        if ($nextCursor !== null
            && $nextCursor->authorContextId() !== $author->cursorContextId()) {
            throw new ValidationException("Works-by-Author continuation does not match the Author.");
        }
        if (!array_is_list($providerAttempts)) {
            throw new ValidationException("Works-by-Author provider attempts must be a list.");
        }
        foreach ($providerAttempts as $attempt) {
            if (!$attempt instanceof BibliographicSearchProviderAttempt) {
                throw new ValidationException("Works-by-Author provider attempts contain invalid data.");
            }
        }
    }

    public function author(): BibliographicAuthorReference { return $this->author; }
    /** @return list<BibliographicWorkSearchResult> */ public function items(): array { return $this->items; }
    public function nextCursor(): ?BibliographicAuthorWorkSearchCursor { return $this->nextCursor; }
    /** @return list<BibliographicSearchProviderAttempt> */ public function providerAttempts(): array { return $this->providerAttempts; }
}
