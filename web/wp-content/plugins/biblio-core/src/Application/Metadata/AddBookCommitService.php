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
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\Work;
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
        private EditionMetadataProvenanceRepository $provenance
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
        if ($local?->type() === LocalEditionResolutionType::LocalAmbiguous) {
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

        $participant = new AddBookCommitEvidenceWriter(
            $this->reviews,
            $this->observations,
            $this->provenance,
            $actorId,
            $libraryId,
            $observations,
            $candidate,
            $now
        );
        $itemId = $this->ids->nextItemId();
        $newWorkId = $this->ids->nextWorkId();
        $newEditionId = $this->ids->nextEditionId();
        $existingEdition = $local?->type()
            === LocalEditionResolutionType::LocalExact;

        if ($existingEdition) {
            $item = $this->items->addForExistingEdition(
                $libraryId,
                $itemId,
                $local->requireEdition()->id(),
                $request->classification(),
                inventoryNumber: $request->inventoryNumber(),
                locationId: $request->locationId(),
                participant: $participant
            );
        } else {
            $title = $this->effectiveTitle($request, $candidate);
            $item = $this->items->addWithNewWorkAndEdition(
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

        $edition = $this->requireEdition($item->editionId());
        $work = $this->requireWork($edition);
        $raceReusedEdition = !$existingEdition
            && !$edition->id()->equals($newEditionId);

        return new AddBookCommitResult(
            $work,
            $edition,
            $item,
            $existingEdition || $raceReusedEdition
        );
    }

    private function selectedCandidate(
        AddBookCommitRequest $request,
        \Biblio\Core\Identity\UserId $actorId,
        LibraryId $libraryId,
        \DateTimeImmutable $at
    ): ?MetadataCandidate {
        $selection = $request->selection();
        if ($selection->type() === AddBookSelectionType::Manual) {
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
}
