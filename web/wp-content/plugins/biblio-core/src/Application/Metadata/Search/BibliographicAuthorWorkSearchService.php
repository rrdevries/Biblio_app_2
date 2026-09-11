<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkSearchService
{
    public const int PAGE_SIZE = 10;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private BibliographicAuthorWorkSearchProvider $localWorks,
        private BibliographicAuthorWorkSearchProvider $externalWorks,
        private BibliographicAuthorWorkMappingLookup $workMappings
    ) {
    }

    public function search(
        BibliographicAuthorWorkSearchRequest $request
    ): BibliographicAuthorWorkSearchPage {
        $this->authenticatedUser->requireUserId();
        $author = $request->author();
        $cursor = $request->cursor();
        $items = [];

        if ($author->authorId() !== null
            && $cursor?->lane() !== BibliographicAuthorWorkSearchLane::External) {
            $offset = $cursor?->nextOffset() ?? 0;
            $local = $this->localWorks->searchWorksForAuthor(
                $author,
                $offset,
                self::PAGE_SIZE
            );
            $this->assertLocalItems($local->items());
            $items = $local->items();
            if ($local->nextOffset() !== null) {
                return new BibliographicAuthorWorkSearchPage(
                    $author,
                    $items,
                    BibliographicAuthorWorkSearchCursor::forAuthor(
                        $author,
                        BibliographicAuthorWorkSearchLane::Local,
                        $local->nextOffset()
                    )
                );
            }
        }

        $providerAuthor = $this->providerAuthor($author);
        if ($providerAuthor === null) {
            if ($cursor?->lane() === BibliographicAuthorWorkSearchLane::External) {
                throw new ValidationException(
                    "Selected Author has no supported provider Author identity."
                );
            }
            return new BibliographicAuthorWorkSearchPage($author, $items, null);
        }

        $remaining = self::PAGE_SIZE - count($items);
        if ($remaining === 0) {
            return new BibliographicAuthorWorkSearchPage(
                $author,
                $items,
                BibliographicAuthorWorkSearchCursor::forAuthor(
                    $author,
                    BibliographicAuthorWorkSearchLane::External,
                    0
                )
            );
        }

        $offset = $cursor?->lane() === BibliographicAuthorWorkSearchLane::External
            ? $cursor->nextOffset()
            : 0;
        try {
            $external = $this->externalWorks->searchWorksForAuthor(
                $author,
                $offset,
                $remaining
            );
            $this->assertExternalItems($external->items(), $providerAuthor->providerKey());
            $externalItems = $this->deduplicateExternal(
                $external->items(),
                $author,
                $providerAuthor->providerKey()
            );
            $items = $this->deduplicatePage([...$items, ...$externalItems]);
            usort($items, static fn ($left, $right): int => $left->sortKey() <=> $right->sortKey());
            $next = $external->nextOffset() === null
                ? null
                : BibliographicAuthorWorkSearchCursor::forAuthor(
                    $author,
                    BibliographicAuthorWorkSearchLane::External,
                    $external->nextOffset()
                );
            return new BibliographicAuthorWorkSearchPage(
                $author,
                $items,
                $next,
                [new BibliographicSearchProviderAttempt(
                    $this->externalWorks->key(),
                    $external->items() === []
                        ? ProviderLookupStatus::Miss
                        : ProviderLookupStatus::Candidates
                )]
            );
        } catch (BibliographicSearchProviderFailure $failure) {
            return new BibliographicAuthorWorkSearchPage(
                $author,
                $items,
                null,
                [new BibliographicSearchProviderAttempt(
                    $this->externalWorks->key(),
                    $failure->status(),
                    $failure->reason()
                )]
            );
        }
    }

    private function providerAuthor(
        BibliographicAuthorReference $author
    ): ?BibliographicProviderEntityIdentity {
        $identity = $author->providerIdentity();
        if ($identity !== null && $identity->providerKey() === $this->externalWorks->key()) {
            return $identity;
        }
        if ($author->authorId() === null) {
            throw new ValidationException("Selected external Author is unsupported by this provider.");
        }
        return null;
    }

    /** @param list<BibliographicWorkSearchResult> $items */
    private function assertLocalItems(array $items): void
    {
        foreach ($items as $item) {
            if ($item->reference()->workId() === null
                || $item->reference()->providerIdentity() !== null) {
                throw new ValidationException("Local Works-by-Author returned non-canonical identity.");
            }
        }
    }

    /** @param list<BibliographicWorkSearchResult> $items */
    private function assertExternalItems(array $items, string $providerKey): void
    {
        foreach ($items as $item) {
            $identity = $item->reference()->providerIdentity();
            if ($item->reference()->workId() !== null || $identity === null
                || $identity->providerKey() !== $providerKey) {
                throw new ValidationException("External Works-by-Author returned mismatched identity.");
            }
        }
    }

    /**
     * @param list<BibliographicWorkSearchResult> $items
     * @return list<BibliographicWorkSearchResult>
     */
    private function deduplicateExternal(
        array $items,
        BibliographicAuthorReference $author,
        string $providerKey
    ): array {
        $authorId = $author->authorId();
        if ($authorId === null || $items === []) {
            return $this->deduplicatePage($items);
        }
        $recordIds = [];
        foreach ($items as $item) {
            $identity = $item->reference()->providerIdentity();
            if ($identity !== null && $identity->providerKey() === $providerKey) {
                $recordIds[] = $identity->providerRecordId();
            }
        }
        $mapped = $this->workMappings->mappedWorksForAuthor(
            $authorId,
            $providerKey,
            array_values(array_unique($recordIds))
        );
        return $this->deduplicatePage(array_values(array_filter(
            $items,
            static function (BibliographicWorkSearchResult $item) use ($mapped): bool {
                $recordId = $item->reference()->providerIdentity()?->providerRecordId();
                return $recordId === null || !isset($mapped[$recordId]);
            }
        )));
    }

    /**
     * @param list<BibliographicWorkSearchResult> $items
     * @return list<BibliographicWorkSearchResult>
     */
    private function deduplicatePage(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $duplicate = false;
            foreach ($item->reference()->strongIdentityKeys() as $identity) {
                if (isset($seen[$identity])) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) { continue; }
            foreach ($item->reference()->strongIdentityKeys() as $identity) {
                $seen[$identity] = true;
            }
            $result[] = $item;
        }
        return $result;
    }
}
