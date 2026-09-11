<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupService;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupIdGenerator;
use Biblio\Core\Application\Metadata\MetadataLookupStatus;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\WorkRepository;
use DateInterval;

final readonly class BibliographicDiscoveryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private IsbnCanonicalizer $canonicalizer,
        private BibliographicLocalDiscoveryRepository $local,
        private LocalEditionResolver $localEditions,
        private WorkRepository $works,
        private FirstSufficientMetadataLookupService $isbnLookup,
        private BibliographicTextDiscoveryProvider $primaryTextProvider,
        private BibliographicTextDiscoveryProvider $fallbackTextProvider,
        private BibliographicProviderIdentityRepository $providerIdentities,
        private BibliographicDiscoverySnapshotRepository $snapshots,
        private MetadataLookupIdGenerator $ids,
        private MetadataClock $clock,
        private TransactionManager $transactions
    ) {
    }

    public function discover(string $input): BibliographicDiscoveryResult
    {
        $actor = $this->authenticatedUser->requireUserId();
        $parsed = $this->canonicalizer->parse($input);
        if ($parsed->isValid() && $parsed->identity() !== null) {
            $query = BibliographicDiscoveryQuery::isbn($parsed->identity());
            $local = $this->localEditions->resolveIdentity($parsed->identity());
            if ($local->editions() !== []) {
                $candidates = [];
                foreach ($local->editions() as $order => $edition) {
                    $work = $this->works->find($edition->workId());
                    if ($work !== null) {
                        $candidates[] = BibliographicDiscoveryCandidate::localEdition(
                            $work->id(), $edition->id(), $edition->title(), $query,
                            $order, $parsed->identity()
                        );
                    }
                }
                return new BibliographicDiscoveryResult(
                    $query,
                    $candidates === [] ? BibliographicDiscoveryStatus::NoResults : BibliographicDiscoveryStatus::Results,
                    $candidates,
                    null
                );
            }
            $lookup = $this->isbnLookup->lookup($parsed->identity());
            $candidates = [];
            foreach ($lookup->candidates() as $order => $classified) {
                $candidate = $classified->candidate();
                if ($candidate->title() === null) { continue; }
                $candidates[] = BibliographicDiscoveryCandidate::external(
                    BibliographicCandidateType::ExternalEdition,
                    $candidate->providerKey(),
                    $candidate->providerRecordId(),
                    $candidate->workLink()?->providerWorkKey(),
                    $candidate->retrievedAt(),
                    MetadataMatchMethod::ExactIsbn,
                    $query,
                    $candidate->title(),
                    $candidate->returnedIsbn(),
                    $candidate->subtitle(),
                    $candidate->contributors(),
                    $candidate->languages(),
                    $candidate->publishers(),
                    $candidate->publicationDate(),
                    $candidate->pageCount(),
                    $candidate->format(),
                    $order
                );
            }
            if ($candidates !== []) {
                return $this->snapshot($query, $candidates, $actor, []);
            }
            return new BibliographicDiscoveryResult(
                $query,
                $lookup->status() === MetadataLookupStatus::ProviderFailure
                    ? BibliographicDiscoveryStatus::ProviderFailure
                    : BibliographicDiscoveryStatus::NoResults,
                [],
                null
            );
        }

        $text = new BibliographicTextQuery($input);
        $query = BibliographicDiscoveryQuery::text($text);
        $local = $this->local->searchText($query);

        $attempts = [];
        $primary = $this->primaryTextProvider->search($text, $query);
        $attempts[] = $this->attempt($this->primaryTextProvider, $primary);
        if ($primary->candidatesList() !== []) {
            return $this->textResults(
                $query, $local, $primary->candidatesList(), $actor, $attempts
            );
        }
        $fallback = $this->fallbackTextProvider->search($text, $query);
        $attempts[] = $this->attempt($this->fallbackTextProvider, $fallback);
        if ($fallback->candidatesList() !== []) {
            return $this->textResults(
                $query, $local, $fallback->candidatesList(), $actor, $attempts
            );
        }

        if ($local !== []) {
            return new BibliographicDiscoveryResult(
                $query,
                BibliographicDiscoveryStatus::Results,
                $local,
                null,
                $attempts
            );
        }

        return new BibliographicDiscoveryResult(
            $query,
            $this->emptyStatus($primary, $fallback),
            [],
            null,
            $attempts
        );
    }

    /**
     * @param list<BibliographicDiscoveryCandidate> $local
     * @param list<BibliographicDiscoveryCandidate> $external
     * @param list<array{provider_key:string,status:string,failure_reason:?string}> $attempts
     */
    private function textResults(
        BibliographicDiscoveryQuery $query,
        array $local,
        array $external,
        \Biblio\Core\Identity\UserId $actor,
        array $attempts
    ): BibliographicDiscoveryResult {
        $external = $this->withoutCanonicalLocalDuplicates($local, $external);
        if ($external === []) {
            return new BibliographicDiscoveryResult(
                $query,
                BibliographicDiscoveryStatus::Results,
                $local,
                null,
                $attempts
            );
        }

        return $this->snapshot(
            $query,
            $external,
            $actor,
            $attempts,
            array_merge($local, $external)
        );
    }

    /**
     * Deduplicate only identities already proven by canonical/provider mappings.
     * Titles and contributors deliberately play no part in this decision.
     *
     * @param list<BibliographicDiscoveryCandidate> $local
     * @param list<BibliographicDiscoveryCandidate> $external
     * @return list<BibliographicDiscoveryCandidate>
     */
    private function withoutCanonicalLocalDuplicates(array $local, array $external): array
    {
        $workIds = [];
        $editionIds = [];
        $isbns = [];
        foreach ($local as $candidate) {
            if ($candidate->workId() !== null) {
                $workIds[$candidate->workId()->value()] = true;
            }
            if ($candidate->editionId() !== null) {
                $editionIds[$candidate->editionId()->value()] = true;
            }
            if ($candidate->isbn() !== null) {
                $isbns[$candidate->isbn()->isbn13()->value()] = true;
            }
        }

        return array_values(array_filter(
            $external,
            function (BibliographicDiscoveryCandidate $candidate) use (
                $workIds, $editionIds, $isbns
            ): bool {
                $provider = $candidate->providerKey();
                $recordId = $candidate->providerRecordId();
                if ($provider === null || $recordId === null) {
                    return true;
                }

                if ($candidate->type() === BibliographicCandidateType::ExternalWork) {
                    $mapped = $candidate->providerWorkId() === null
                        ? null
                        : $this->providerIdentities->findWork(
                            $provider, "work", $candidate->providerWorkId()
                        );
                    $mapped ??= $this->providerIdentities->findWork(
                        $provider, "work", $recordId
                    );
                    return $mapped === null || !isset($workIds[$mapped->value()]);
                }

                $mappedEdition = $this->providerIdentities->findEdition(
                    $provider, $recordId
                );
                if ($mappedEdition !== null && isset($editionIds[$mappedEdition->value()])) {
                    return false;
                }
                return $candidate->isbn() === null
                    || !isset($isbns[$candidate->isbn()->isbn13()->value()]);
            }
        ));
    }

    /**
     * @param list<BibliographicDiscoveryCandidate> $candidates
     * @param list<array{provider_key:string,status:string,failure_reason:?string}> $attempts
     * @param ?list<BibliographicDiscoveryCandidate> $presentedCandidates
     */
    private function snapshot(
        BibliographicDiscoveryQuery $query,
        array $candidates,
        \Biblio\Core\Identity\UserId $actor,
        array $attempts,
        ?array $presentedCandidates = null
    ): BibliographicDiscoveryResult {
        $id = $this->ids->next();
        $now = $this->clock->now();
        $snapshot = new BibliographicDiscoverySnapshot(
            $id,
            $actor,
            $query,
            $now,
            $now->add(new DateInterval("PT30M")),
            $candidates
        );
        $this->transactions->run(function () use ($snapshot): void {
            $this->snapshots->save($snapshot);
        });
        return new BibliographicDiscoveryResult(
            $query,
            BibliographicDiscoveryStatus::Results,
            $presentedCandidates ?? $candidates,
            $id,
            $attempts
        );
    }

    /** @return array{provider_key:string,status:string,failure_reason:?string} */
    private function attempt(
        BibliographicTextDiscoveryProvider $provider,
        BibliographicProviderDiscoveryResult $result
    ): array {
        return [
            "provider_key" => $provider->key(),
            "status" => $result->status()->value,
            "failure_reason" => $result->failureReason()?->value,
        ];
    }

    private function emptyStatus(
        BibliographicProviderDiscoveryResult $primary,
        BibliographicProviderDiscoveryResult $fallback
    ): BibliographicDiscoveryStatus {
        $statuses = [$primary->status(), $fallback->status()];
        if (in_array(ProviderLookupStatus::InvalidResponse, $statuses, true)) {
            return BibliographicDiscoveryStatus::InvalidProviderResponse;
        }
        if ($statuses === [ProviderLookupStatus::ConfigurationError, ProviderLookupStatus::ConfigurationError]) {
            return BibliographicDiscoveryStatus::ConfigurationFailure;
        }
        foreach ($statuses as $status) {
            if (match ($status) {
                ProviderLookupStatus::Miss => false,
                ProviderLookupStatus::Candidates,
                ProviderLookupStatus::Unavailable,
                ProviderLookupStatus::RateLimited,
                ProviderLookupStatus::ConfigurationError => true,
            }) {
                return BibliographicDiscoveryStatus::ProviderFailure;
            }
        }
        return BibliographicDiscoveryStatus::NoResults;
    }
}
