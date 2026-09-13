<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;

final readonly class BibliographicTextSearchService
{
    public const int PAGE_SIZE = 10;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private BibliographicAuthorSearchProvider $localAuthors,
        private BibliographicWorkSearchProvider $localWorks,
        private BibliographicAuthorSearchProvider $externalAuthors,
        private BibliographicWorkSearchProvider $externalWorks,
        private BibliographicProviderIdentityRepository $providerIdentities,
        private BibliographicAuthorProviderIdentityLookup $authorProviderIdentities,
        private BibliographicAuthorDisambiguationLookup $authorDisambiguations
    ) {
    }

    public function search(BibliographicTextSearchRequest $request): BibliographicTextSearchResult
    {
        $this->authenticatedUser->requireUserId();
        [$authors, $authorAttempts] = $this->authors($request);
        [$works, $workAttempts] = $this->works($request);

        return new BibliographicTextSearchResult(
            $request->query(),
            $authors,
            $works,
            $authorAttempts,
            $workAttempts
        );
    }

    /**
     * @return array{BibliographicAuthorSearchPage,list<BibliographicSearchProviderAttempt>}
     */
    private function authors(BibliographicTextSearchRequest $request): array
    {
        $cursor = $request->authorCursor();
        $query = $request->query();
        $items = [];

        if ($cursor?->lane() !== BibliographicAuthorSearchLane::External) {
            $offset = $cursor?->nextOffset() ?? 0;
            $local = $this->localAuthors->searchAuthors($query, $offset, self::PAGE_SIZE);
            $this->assertAuthorSourceProgress($local, $offset, self::PAGE_SIZE);
            $items = $this->canonicalAuthorDisambiguation(
                $this->canonicalAuthorEvidence($local->items())
            );
            if ($local->nextOffset() !== null) {
                return [new BibliographicAuthorSearchPage(
                    $query,
                    $items,
                    new BibliographicAuthorSearchCursor(
                        $query,
                        BibliographicAuthorSearchLane::Local,
                        $local->nextOffset()
                    )
                ), []];
            }
        }

        $remaining = self::PAGE_SIZE - count($items);
        if ($remaining === 0) {
            return [new BibliographicAuthorSearchPage(
                $query,
                $items,
                new BibliographicAuthorSearchCursor(
                    $query,
                    BibliographicAuthorSearchLane::External,
                    0
                )
            ), []];
        }

        $externalOffset = $cursor?->lane() === BibliographicAuthorSearchLane::External
            ? $cursor->nextOffset()
            : 0;
        [$external, $attempt] = $this->externalAuthors(
            $query,
            $externalOffset,
            $remaining
        );
        $items = $this->deduplicateAuthors([
            ...$items,
            ...$this->unmappedExternalAuthors($external->items()),
        ]);
        usort($items, static fn ($left, $right): int => $left->sortKey() <=> $right->sortKey());
        $next = $external->nextOffset() === null
            ? null
            : new BibliographicAuthorSearchCursor(
                $query,
                BibliographicAuthorSearchLane::External,
                $external->nextOffset()
            );

        return [new BibliographicAuthorSearchPage($query, $items, $next), [$attempt]];
    }

    /**
     * @return array{BibliographicWorkSearchPage,list<BibliographicSearchProviderAttempt>}
     */
    private function works(BibliographicTextSearchRequest $request): array
    {
        $cursor = $request->workCursor();
        $local = $cursor?->kind() === BibliographicSearchResultKind::ExternalCandidate
            ? new BibliographicWorkSearchPage($request->query(), [], null)
            : $this->localWorks->searchWorks($request->query(), $cursor);

        [$external, $attempt] = $this->externalWorks($request->query(), $cursor);
        if ($local->nextCursor() !== null) {
            return [$local, [$attempt]];
        }

        $localWorkIds = [];
        foreach ($local->items() as $item) {
            $workId = $item->reference()->workId();
            if ($workId !== null) { $localWorkIds[$workId->value()] = true; }
        }
        $externalItems = array_values(array_filter(
            $external->items(),
            function (BibliographicWorkSearchResult $item) use ($localWorkIds): bool {
                $identity = $item->reference()->providerIdentity();
                if ($identity === null) { return true; }
                $mapped = $this->providerIdentities->findWork(
                    $identity->providerKey(),
                    "work",
                    $identity->providerRecordId()
                );
                return $mapped === null || !isset($localWorkIds[$mapped->value()]);
            }
        ));
        $items = $this->deduplicateWorks([...$local->items(), ...$externalItems]);
        usort($items, static fn ($left, $right): int => $left->sortKey() <=> $right->sortKey());
        $hasMore = count($items) > self::PAGE_SIZE || $external->nextCursor() !== null;
        $items = array_slice($items, 0, self::PAGE_SIZE);
        $next = $hasMore && $items !== []
            ? $items[array_key_last($items)]->cursor($request->query())
            : null;

        return [new BibliographicWorkSearchPage($request->query(), $items, $next), [$attempt]];
    }

    /** @return array{BibliographicAuthorSearchSourcePage,BibliographicSearchProviderAttempt} */
    private function externalAuthors(
        BibliographicTextSearchQuery $query,
        int $offset,
        int $limit
    ): array {
        try {
            $page = $this->externalAuthors->searchAuthors($query, $offset, $limit);
            $this->assertAuthorSourceProgress($page, $offset, $limit);
            return [$page, new BibliographicSearchProviderAttempt(
                $this->externalAuthors->key(),
                $page->items() === [] ? ProviderLookupStatus::Miss : ProviderLookupStatus::Candidates
            )];
        } catch (BibliographicSearchProviderFailure $failure) {
            return [
                new BibliographicAuthorSearchSourcePage([], null),
                new BibliographicSearchProviderAttempt(
                    $this->externalAuthors->key(),
                    $failure->status(),
                    $failure->reason()
                ),
            ];
        }
    }

    /** @return array{BibliographicWorkSearchPage,BibliographicSearchProviderAttempt} */
    private function externalWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor
    ): array {
        $cursor = $cursor?->kind() === BibliographicSearchResultKind::ExternalCandidate
            ? $cursor
            : null;
        try {
            $page = $this->externalWorks->searchWorks($query, $cursor);
            return [$page, new BibliographicSearchProviderAttempt(
                $this->externalWorks->key(),
                $page->items() === [] ? ProviderLookupStatus::Miss : ProviderLookupStatus::Candidates
            )];
        } catch (BibliographicSearchProviderFailure $failure) {
            return [
                new BibliographicWorkSearchPage($query, [], null),
                new BibliographicSearchProviderAttempt(
                    $this->externalWorks->key(),
                    $failure->status(),
                    $failure->reason()
                ),
            ];
        }
    }

    /**
     * @param list<BibliographicAuthorSearchResult> $items
     * @return list<BibliographicAuthorSearchResult>
     */
    private function deduplicateAuthors(array $items): array
    {
        return $this->deduplicate($items);
    }

    /**
     * @param list<BibliographicAuthorSearchResult> $items
     * @return list<BibliographicAuthorSearchResult>
     */
    private function canonicalAuthorEvidence(array $items): array
    {
        $authorIds = [];
        foreach ($items as $item) {
            $authorId = $item->reference()->authorId();
            if ($authorId !== null) { $authorIds[] = $authorId; }
        }
        if ($authorIds === []) { return $items; }
        $claims = $this->authorProviderIdentities->providerAuthorIdentities(
            $this->externalAuthors->key(),
            $authorIds
        );
        return array_map(
            static function (BibliographicAuthorSearchResult $item) use ($claims): BibliographicAuthorSearchResult {
                $authorId = $item->reference()->authorId();
                if ($authorId === null) { return $item; }
                $identities = $claims[$authorId->value()] ?? [];
                if (count($identities) !== 1) { return $item; }
                return $item->withReference(
                    BibliographicAuthorReference::canonical($authorId, $identities[0])
                );
            },
            $items
        );
    }

    /**
     * @param list<BibliographicAuthorSearchResult> $items
     * @return list<BibliographicAuthorSearchResult>
     */
    private function canonicalAuthorDisambiguation(array $items): array
    {
        $authorIds = [];
        foreach ($items as $item) {
            $authorId = $item->reference()->authorId();
            if ($authorId !== null) { $authorIds[] = $authorId; }
        }
        if ($authorIds === []) { return $items; }

        $contexts = $this->authorDisambiguations->localAuthorDisambiguations($authorIds);
        return array_map(
            static function (BibliographicAuthorSearchResult $item) use ($contexts): BibliographicAuthorSearchResult {
                $authorId = $item->reference()->authorId();
                if ($authorId === null) { return $item; }
                $context = $contexts[$authorId->value()] ?? null;
                if ($context === null) {
                    throw new \LogicException("Canonical Author disambiguation is incomplete.");
                }
                return $item->withDisambiguation($context);
            },
            $items
        );
    }

    /**
     * @param list<BibliographicAuthorSearchResult> $items
     * @return list<BibliographicAuthorSearchResult>
     */
    private function unmappedExternalAuthors(array $items): array
    {
        $recordIds = [];
        foreach ($items as $item) {
            $identity = $item->reference()->providerIdentity();
            if ($identity !== null && $identity->providerKey() === $this->externalAuthors->key()) {
                $recordIds[] = $identity->providerRecordId();
            }
        }
        if ($recordIds === []) { return $items; }
        $mapped = $this->authorProviderIdentities->mappedAuthors(
            $this->externalAuthors->key(),
            array_values(array_unique($recordIds))
        );
        return array_values(array_filter(
            $items,
            static function (BibliographicAuthorSearchResult $item) use ($mapped): bool {
                $recordId = $item->reference()->providerIdentity()?->providerRecordId();
                return $recordId === null || !isset($mapped[$recordId]);
            }
        ));
    }

    private function assertAuthorSourceProgress(
        BibliographicAuthorSearchSourcePage $page,
        int $offset,
        int $limit
    ): void {
        if (count($page->items()) > $limit) {
            throw new \LogicException("Bibliographic Author source exceeded requested capacity.");
        }
        $nextOffset = $page->nextOffset();
        if ($nextOffset !== null
            && ($page->items() === [] || $nextOffset !== $offset + count($page->items()))) {
            throw new \LogicException("Bibliographic Author source did not advance exactly.");
        }
    }

    /**
     * @param list<BibliographicWorkSearchResult> $items
     * @return list<BibliographicWorkSearchResult>
     */
    private function deduplicateWorks(array $items): array
    {
        return $this->deduplicate($items);
    }

    /**
     * @template T of BibliographicAuthorSearchResult|BibliographicWorkSearchResult
     * @param list<T> $items
     * @return list<T>
     */
    private function deduplicate(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $duplicate = false;
            foreach ($item->reference()->strongIdentityKeys() as $key) {
                if (isset($seen[$key])) { $duplicate = true; break; }
            }
            if ($duplicate) { continue; }
            foreach ($item->reference()->strongIdentityKeys() as $key) {
                $seen[$key] = true;
            }
            $result[] = $item;
        }
        return $result;
    }
}
