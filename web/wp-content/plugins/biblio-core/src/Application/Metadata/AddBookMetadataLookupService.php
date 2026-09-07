<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Catalog\Read\BibliographicRelationshipQueryService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;

final readonly class AddBookMetadataLookupService
{
    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private LocalEditionResolver $localEditions,
        private WorkRepository $works,
        private BibliographicRelationshipQueryService $relationships,
        private AddBookExistingItemRepository $existingItems,
        private FirstSufficientMetadataLookupService $metadata,
        private AddBookMetadataReviewPolicy $reviewPolicy,
        private AuthenticatedUser $authenticatedUser,
        private MetadataLookupSnapshotRepository $snapshots,
        private MetadataLookupIdGenerator $lookupIds,
        private TransactionManager $transactionManager,
        private MetadataClock $clock
    ) {
    }

    public function lookup(
        LibraryId $libraryId,
        string $identifier
    ): AddBookMetadataLookupResult {
        $library = $this->libraryContexts->get($libraryId);

        if (!$library->capabilities()->canAddCatalogItem()) {
            throw new AuthorizationException(
                "Add Book is not available in this Library Context."
            );
        }

        $local = $this->localEditions->resolveInput($identifier);

        if ($local->type() === LocalEditionResolutionType::LocalNone) {
            $metadata = $this->metadata->lookup($local->identity());
            $lookupId = null;
            if ($metadata->candidates() !== []) {
                $now = $this->clock->now();
                $lookupId = $this->lookupIds->next();
                $snapshot = new MetadataLookupSnapshot(
                    $lookupId,
                    $this->authenticatedUser->requireUserId(),
                    $libraryId,
                    $local->identity(),
                    $now,
                    $now->modify("+30 minutes"),
                    array_map(
                        static fn (ClassifiedMetadataCandidate $candidate): MetadataCandidate =>
                            $candidate->candidate(),
                        $metadata->candidates()
                    )
                );
                $this->transactionManager->run(function () use ($snapshot): void {
                    $this->snapshots->save($snapshot);
                });
            }

            return AddBookMetadataLookupResult::fromMetadata(
                $library,
                $local->identity(),
                $metadata,
                $this->reviewPolicy,
                $lookupId
            );
        }

        $localEditions = $local->editions();
        $existingItems = $this->existingItems->forEditionsInLibrary(
            $libraryId,
            array_map(
                static fn (Edition $edition): \Biblio\Core\Catalog\EditionId =>
                    $edition->id(),
                $localEditions
            )
        );
        $works = [];
        $workIds = [];
        foreach ($localEditions as $edition) {
            $work = $this->requireWork($edition);
            $works[$edition->id()->value()] = $work;
            $workIds[$work->id()->value()] = $work->id();
        }
        $contributors = $this->relationships->contributorsForWorks(
            array_values($workIds)
        );
        $authorIds = [];
        foreach ($contributors as $workContributors) {
            foreach ($workContributors as $contributor) {
                $authorIds[$contributor->authorId()->value()] =
                    $contributor->authorId();
            }
        }
        $authors = $this->relationships->authors(array_values($authorIds));

        $matches = array_map(function (Edition $edition) use (
            $authors,
            $contributors,
            $existingItems,
            $works
        ): AddBookExistingEdition {
            $work = $works[$edition->id()->value()];
            $workAuthors = [];
            foreach ($contributors[$work->id()->value()] ?? [] as $contributor) {
                $author = $authors[$contributor->authorId()->value()] ?? null;
                if ($author !== null) {
                    $workAuthors[] = $author;
                }
            }

            return new AddBookExistingEdition(
                $work,
                $edition,
                $workAuthors,
                $existingItems[$edition->id()->value()] ?? []
            );
        }, $localEditions);

        return AddBookMetadataLookupResult::local(
            $library,
            $local->identity(),
            $local->type(),
            $matches,
            $this->reviewPolicy
        );
    }

    private function requireWork(Edition $edition): \Biblio\Core\Catalog\Work
    {
        $work = $this->works->find($edition->workId());

        if ($work === null) {
            throw new PersistenceException(
                "Existing Edition references a missing Work.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $work;
    }
}
