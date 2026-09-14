<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\AddLibraryItemCommitter;
use Biblio\Core\Application\Catalog\AmbiguousLocalEdition;
use Biblio\Core\Application\Catalog\LocalEditionResolution;
use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRace;
use Biblio\Core\Application\Metadata\Author\AuthorContributorPositionRace;
use Biblio\Core\Application\Metadata\Author\AuthorIdentityPromotionRace;
use Biblio\Core\Application\Metadata\Author\AuthorProviderClaimRace;
use Biblio\Core\Application\Metadata\Author\CanonicalAuthorMaterializer;
use Biblio\Core\Application\Metadata\Author\ManualAuthorAttempt;
use Biblio\Core\Application\Metadata\Author\ManualAuthorAttemptPlan;
use Biblio\Core\Catalog\ContributorPosition;
use Biblio\Core\Catalog\ContributorRole;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;

final readonly class AddBookCommitService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryContextQueryService $libraryContexts,
        private LocalEditionResolver $localEditions,
        private MetadataLookupSnapshotRepository $snapshots,
        private AddLibraryItemCommitter $items,
        private AddBookRecordIdGenerator $ids,
        private MetadataClock $clock,
        private EditionRepository $editions,
        private WorkRepository $works,
        private MetadataFieldReviewRepository $reviews,
        private UserObservedMetadataEvidenceRepository $observations,
        private EditionMetadataProvenanceRepository $provenance,
        private CanonicalAuthorMaterializer $authorMaterializer
    ) {
    }

    public function commit(
        LibraryId $libraryId,
        AddBookCommitRequest $request
    ): AddBookCommitResult {
        $actorId = $this->authenticatedUser->requireUserId();
        $library = $this->libraryContexts->get($libraryId);
        if (!$library->capabilities()->canAddCatalogItem()) {
            throw new AuthorizationException(
                "Add Book is not available in this Library Context."
            );
        }

        $local = $request->identifier() === null
            ? null
            : $this->localEditions->resolveInput($request->identifier());
        $selectedExistingEdition = $this->selectedExistingEdition(
            $request,
            $local
        );
        if (
            $local?->type() === LocalEditionResolutionType::LocalAmbiguous
            && $selectedExistingEdition === null
        ) {
            throw new AmbiguousLocalEdition();
        }

        $now = $this->clock->now();
        $observations = $local === null
            ? $request->observations()
            : $request->observations()->withCanonicalIsbn(
                $local->identity()->isbn13()->value()
            );
        $candidate = $this->selectedCandidate(
            $request,
            $actorId,
            $libraryId,
            $now
        );
        if (
            $candidate !== null
            && $local !== null
            && $candidate->queriedIsbn()->isbn13()->value()
                !== $local->identity()->isbn13()->value()
        ) {
            throw new MetadataLookupSnapshotUnavailable();
        }

        $itemId = $this->ids->nextItemId();
        $newWorkId = $this->ids->nextWorkId();
        $newEditionId = $this->ids->nextEditionId();
        $participant = new AddBookCommitEvidenceWriter(
            $this->reviews,
            $this->observations,
            $this->provenance,
            $this->authorMaterializer,
            $actorId,
            $libraryId,
            $observations,
            $candidate,
            $now,
            $this->manualAuthorAttemptPlan($request),
            $newWorkId,
            $newEditionId
        );
        $existingEdition = $selectedExistingEdition
            ?? ($local?->type() === LocalEditionResolutionType::LocalExact
                ? $local->requireEdition()
                : null);

        try {
            $item = $this->commitItemOnce(
                $libraryId,
                $request,
                $local,
                $candidate,
                $existingEdition,
                $itemId,
                $newWorkId,
                $newEditionId,
                $participant
            );
        } catch (
            AuthorProviderClaimRace
            |AuthorContributorCreditRace
            |AuthorContributorPositionRace
            |AuthorIdentityPromotionRace
        ) {
            $item = $this->commitItemOnce(
                $libraryId,
                $request,
                $local,
                $candidate,
                $existingEdition,
                $itemId,
                $newWorkId,
                $newEditionId,
                $participant
            );
        }

        $edition = $this->requireEdition($item->editionId());
        $work = $this->requireWork($edition);
        $raceReusedEdition = $existingEdition === null
            && !$edition->id()->equals($newEditionId);

        return new AddBookCommitResult(
            $work,
            $edition,
            $item,
            $existingEdition !== null || $raceReusedEdition
        );
    }

    private function commitItemOnce(
        LibraryId $libraryId,
        AddBookCommitRequest $request,
        ?LocalEditionResolution $local,
        ?MetadataCandidate $candidate,
        ?Edition $existingEdition,
        ItemId $itemId,
        WorkId $newWorkId,
        EditionId $newEditionId,
        AddBookCommitEvidenceWriter $participant
    ): Item {
        if ($existingEdition !== null) {
            return $this->items->addForExistingEdition(
                $libraryId,
                $itemId,
                $existingEdition->id(),
                $request->classification(),
                inventoryNumber: $request->inventoryNumber(),
                locationId: $request->locationId(),
                participant: $participant
            );
        }

        $title = $this->effectiveTitle($request, $candidate);
        if ($request->selection()->workId() !== null) {
            return $this->items->addWithNewEditionForExistingWork(
                $libraryId,
                $itemId,
                $newEditionId,
                $request->selection()->workId(),
                $title,
                $request->classification(),
                isbnMetadata: $local?->identity()->metadata()
                    ?? EditionIsbnMetadata::withoutIsbn(),
                inventoryNumber: $request->inventoryNumber(),
                locationId: $request->locationId(),
                participant: $participant
            );
        }

        return $this->items->addWithNewWorkAndEdition(
            $libraryId,
            $itemId,
            $newWorkId,
            $title,
            $newEditionId,
            $request->classification(),
            isbnMetadata: $local?->identity()->metadata()
                ?? EditionIsbnMetadata::withoutIsbn(),
            inventoryNumber: $request->inventoryNumber(),
            locationId: $request->locationId(),
            participant: $participant
        );
    }

    private function selectedExistingEdition(
        AddBookCommitRequest $request,
        ?LocalEditionResolution $local
    ): ?Edition {
        $selectedId = $request->selection()->editionId();
        if ($selectedId === null) {
            return null;
        }

        foreach ($local?->editions() ?? [] as $edition) {
            if ($edition->id()->equals($selectedId)) {
                return $edition;
            }
        }

        throw new ValidationException(
            "Selected Edition is not available for this Add Book request."
        );
    }

    private function selectedCandidate(
        AddBookCommitRequest $request,
        \Biblio\Core\Identity\UserId $actorId,
        LibraryId $libraryId,
        \DateTimeImmutable $at
    ): ?MetadataCandidate {
        $selection = $request->selection();
        if ($selection->type() !== AddBookSelectionType::Candidate) {
            return null;
        }

        return $this->snapshots->candidateForCommit(
            $selection->lookupId()
                ?? throw new MetadataLookupSnapshotUnavailable(),
            $selection->candidateId()
                ?? throw new MetadataLookupSnapshotUnavailable(),
            $actorId,
            $libraryId,
            $at
        ) ?? throw new MetadataLookupSnapshotUnavailable();
    }

    private function effectiveTitle(
        AddBookCommitRequest $request,
        ?MetadataCandidate $candidate
    ): string {
        $value = $request->observations()->value(MetadataField::Title)?->value()
            ?? $candidate?->title();
        if (!is_string($value)) {
            throw new ValidationException(
                "Add Book requires an observed or selected title."
            );
        }

        return $value;
    }

    private function requireEdition(\Biblio\Core\Catalog\EditionId $id): Edition
    {
        return $this->editions->find($id)
            ?? throw new PersistenceException("Committed Edition is missing.");
    }

    private function requireWork(Edition $edition): Work
    {
        return $this->works->find($edition->workId())
            ?? throw new PersistenceException("Committed Work is missing.");
    }

    private function manualAuthorAttemptPlan(
        AddBookCommitRequest $request
    ): ManualAuthorAttemptPlan {
        if ($request->selection()->type() !== AddBookSelectionType::Manual) {
            return new ManualAuthorAttemptPlan([]);
        }

        $authors = [];
        foreach ($request->authors() as $offset => $author) {
            $authors[] = new ManualAuthorAttempt(
                $this->ids->nextManualAuthorObservationId(),
                $author->displayName(),
                ContributorRole::Author,
                new ContributorPosition($offset + 1)
            );
        }

        return new ManualAuthorAttemptPlan($authors);
    }
}
