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
        private BibliographicProviderIdentityRepository $providerIdentities
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
        $local = $cursor?->kind() === BibliographicSearchResultKind::ExternalCandidate
            ? new BibliographicAuthorSearchPage($request->query(), [], null)
            : $this->localAuthors->searchAuthors($request->query(), $cursor);

        [$external, $attempt] = $this->externalAuthors($request->query(), $cursor);
        if ($local->nextCursor() !== null) {
            return [$local, [$attempt]];
        }

        $items = $this->deduplicateAuthors([...$local->items(), ...$external->items()]);
        usort($items, static fn ($left, $right): int => $left->sortKey() <=> $right->sortKey());
        $hasMore = count($items) > self::PAGE_SIZE || $external->nextCursor() !== null;
        $items = array_slice($items, 0, self::PAGE_SIZE);
        $next = $hasMore && $items !== []
            ? $items[array_key_last($items)]->cursor($request->query())
            : null;

        return [new BibliographicAuthorSearchPage($request->query(), $items, $next), [$attempt]];
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

    /** @return array{BibliographicAuthorSearchPage,BibliographicSearchProviderAttempt} */
    private function externalAuthors(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor
    ): array {
        $cursor = $cursor?->kind() === BibliographicSearchResultKind::ExternalCandidate
            ? $cursor
            : null;
        try {
            $page = $this->externalAuthors->searchAuthors($query, $cursor);
            return [$page, new BibliographicSearchProviderAttempt(
                $this->externalAuthors->key(),
                $page->items() === [] ? ProviderLookupStatus::Miss : ProviderLookupStatus::Candidates
            )];
        } catch (BibliographicSearchProviderFailure $failure) {
            return [
                new BibliographicAuthorSearchPage($query, [], null),
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
