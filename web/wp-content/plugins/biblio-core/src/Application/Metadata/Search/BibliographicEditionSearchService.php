<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;

final readonly class BibliographicEditionSearchService
{
    public const int PAGE_SIZE = 10;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private BibliographicEditionSearchProvider $localEditions,
        private BibliographicExternalEditionSearchProvider $externalEditions,
        private BibliographicWorkProviderIdentityLookup $workIdentities,
        private BibliographicProviderIdentityRepository $providerIdentities,
        private EditionIdentifierClaimRepository $isbnClaims,
        private EditionRepository $editions
    ) {
    }

    public function search(BibliographicEditionSearchRequest $request): BibliographicEditionSearchPage
    {
        $this->authenticatedUser->requireUserId();
        $work = $request->work();
        $cursor = $request->cursor();
        $items = [];

        if ($work->workId() !== null
            && $cursor?->lane() !== BibliographicEditionSearchLane::External) {
            $offset = $cursor?->nextOffset() ?? 0;
            $local = $this->localEditions->searchEditions($work, $offset, self::PAGE_SIZE);
            $items = $local->items();
            if ($local->nextOffset() !== null) {
                return new BibliographicEditionSearchPage(
                    $work,
                    $items,
                    BibliographicEditionSearchCursor::forWork(
                        $work,
                        BibliographicEditionSearchLane::Local,
                        $local->nextOffset()
                    )
                );
            }
        }

        $providerWork = $this->providerWork($work);
        if ($providerWork === null) {
            if ($cursor?->lane() === BibliographicEditionSearchLane::External) {
                throw new ValidationException("Selected Work has no unambiguous provider Work identity.");
            }
            return new BibliographicEditionSearchPage($work, $items, null);
        }

        $remaining = self::PAGE_SIZE - count($items);
        if ($remaining === 0) {
            return new BibliographicEditionSearchPage(
                $work,
                $items,
                BibliographicEditionSearchCursor::forWork(
                    $work,
                    BibliographicEditionSearchLane::External,
                    0
                )
            );
        }

        $offset = $cursor?->lane() === BibliographicEditionSearchLane::External
            ? $cursor->nextOffset()
            : 0;
        try {
            $external = $this->externalEditions->searchEditionsForProviderWork(
                $work,
                $providerWork,
                $offset,
                $remaining
            );
            foreach ($external->items() as $candidate) {
                if ($candidate->providerWorkIdentity()?->stableKey() !== $providerWork->stableKey()) {
                    throw new ValidationException(
                        "Provider Edition result does not belong to the requested provider Work."
                    );
                }
            }
            $externalItems = $this->deduplicateExternal($external->items(), $work->workId());
            $items = $this->deduplicatePage([...$items, ...$externalItems]);
            usort($items, static fn ($left, $right): int => $left->sortKey() <=> $right->sortKey());
            $next = $external->nextOffset() === null
                ? null
                : BibliographicEditionSearchCursor::forWork(
                    $work,
                    BibliographicEditionSearchLane::External,
                    $external->nextOffset()
                );
            return new BibliographicEditionSearchPage(
                $work,
                $items,
                $next,
                [new BibliographicSearchProviderAttempt(
                    $this->externalEditions->key(),
                    $external->items() === []
                        ? ProviderLookupStatus::Miss
                        : ProviderLookupStatus::Candidates
                )]
            );
        } catch (BibliographicSearchProviderFailure $failure) {
            return new BibliographicEditionSearchPage(
                $work,
                $items,
                null,
                [new BibliographicSearchProviderAttempt(
                    $this->externalEditions->key(),
                    $failure->status(),
                    $failure->reason()
                )]
            );
        }
    }

    private function providerWork(
        BibliographicWorkReference $work
    ): ?BibliographicProviderEntityIdentity {
        $identity = $work->providerIdentity();
        if ($identity !== null && $identity->providerKey() === $this->externalEditions->key()) {
            return $identity;
        }
        if ($work->workId() === null) {
            throw new ValidationException("Selected external Work is unsupported by this provider.");
        }
        $identities = $this->workIdentities->providerWorkIdentities(
            $work->workId(),
            $this->externalEditions->key()
        );
        return count($identities) === 1 ? $identities[0] : null;
    }

    /**
     * @param list<BibliographicEditionSearchResult> $items
     * @return list<BibliographicEditionSearchResult>
     */
    private function deduplicateExternal(array $items, ?WorkId $canonicalWork): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($canonicalWork !== null && $this->matchesCanonical($item, $canonicalWork)) {
                continue;
            }
            $result[] = $item;
        }
        return $this->deduplicatePage($result);
    }

    private function matchesCanonical(
        BibliographicEditionSearchResult $candidate,
        WorkId $selectedWork
    ): bool {
        $provider = $candidate->reference()->providerIdentity();
        $mappedId = $provider === null ? null : $this->providerIdentities->findEdition(
            $provider->providerKey(),
            $provider->providerRecordId()
        );
        $isbnId = $candidate->isbn() === null ? null : $this->isbnClaims->findByCanonicalIsbn13(
            $candidate->isbn()->isbn13()
        );
        $matched = false;
        foreach (array_filter([$mappedId, $isbnId]) as $editionId) {
            $edition = $this->editions->find($editionId);
            if ($edition === null) {
                throw new PersistenceException(
                    "Strong Edition identity references a missing canonical Edition.",
                    failureReason: FailureReason::PersistenceReadFailed
                );
            }
            if ($edition->workId()->value() !== $selectedWork->value()) {
                throw new ValidationException(
                    "Provider Edition identity conflicts with the selected canonical Work."
                );
            }
            $matched = true;
        }
        return $matched;
    }

    /**
     * @param list<BibliographicEditionSearchResult> $items
     * @return list<BibliographicEditionSearchResult>
     */
    private function deduplicatePage(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $duplicate = false;
            foreach ($item->strongIdentityKeys() as $identity) {
                if (isset($seen[$identity])) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) { continue; }
            foreach ($item->strongIdentityKeys() as $identity) {
                $seen[$identity] = true;
            }
            $result[] = $item;
        }
        return $result;
    }
}
