<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\ItemLocalDetailsRecorder;
use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationLedgerRepository;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Catalog\CanonicalIsbnAlreadyClaimed;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\CatalogRecordAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Catalog\Classification\LibrarySubjectRepository;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemLocalDetailsRepository;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\LocationRepository;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\WritableEditionRepository;
use Biblio\Core\Catalog\WritableWorkRepository;
use Biblio\Core\Library\LibraryId;
use Throwable;

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class CatalogMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private CatalogMigrationRecordIdGenerator $ids,
        private WritableWorkRepository $works,
        private WritableEditionRepository $editions,
        private CatalogMigrationItemRepository $items,
        private LocalEditionResolver $editionResolver,
        private EditionIdentifierClaimRepository $isbnClaims,
        private LibraryCatalogContextRepository $contexts,
        private LibraryCatalogContextInitializer $contextInitializer,
        private LocationRepository $locations,
        private LibraryBookTypeRepository $bookTypes,
        private LibraryGenreRepository $genres,
        private LibrarySubjectRepository $subjects,
        private ItemLocalDetailsRepository $localDetails,
        private ItemLocalDetailsRecorder $localDetailsRecorder
    ) {
    }

    public function validateItemPlan(CatalogItemPlan $plan): void
    {
        $libraryId = $plan->targetLibraryId();
        $selection = $plan->classification();
        if ($this->bookTypes->find($libraryId, $selection->bookTypeId()) === null) {
            throw $this->failure(
                CatalogMigrationReason::InvalidClassificationTarget,
                "Planned Book Type is unavailable in the target Library."
            );
        }
        foreach ($selection->genreIds() as $id) {
            if ($this->genres->find($libraryId, $id) === null) {
                throw $this->failure(
                    CatalogMigrationReason::InvalidClassificationTarget,
                    "Planned Genre is unavailable in the target Library."
                );
            }
        }
        foreach ($selection->subjectIds() as $id) {
            if ($this->subjects->find($libraryId, $id) === null) {
                throw $this->failure(
                    CatalogMigrationReason::InvalidClassificationTarget,
                    "Planned Subject is unavailable in the target Library."
                );
            }
        }
        if ($plan->locationId() !== null) {
            foreach ($this->locations->forLibrary($libraryId) as $location) {
                if ($location->id()->value() === $plan->locationId()->value()) {
                    return;
                }
            }
            throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Planned Location is unavailable in the target Library."
            );
        }
    }

    public function applyWork(
        SourceObservation $observation,
        CatalogWorkPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $mapped = $this->mappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "work"
        );
        $approved = $plan->approvedExistingWorkId()?->value();
        if ($mapped !== null && $approved !== null && $mapped !== $approved) {
            throw $this->failure(
                CatalogMigrationReason::UnsafeWorkMapping,
                "Work mapping conflicts with the approved canonical target."
            );
        }
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                $observation
            );
        }

        if ($plan->aliasOfSourceId() !== null) {
            $representative = $this->requireMappedTarget(
                $run,
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                $plan->aliasOfSourceId(),
                "work"
            );
            if ($mapped !== null && $mapped !== $representative) {
                throw $this->failure(
                    CatalogMigrationReason::DivergentReplay,
                    "Work alias conflicts with its representative source mapping."
                );
            }
            $work = $this->works->find(new WorkId($representative));
            if ($work === null) {
                throw $this->failure(
                    CatalogMigrationReason::MissingTargetReference,
                    "Representative Work target does not exist."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping("work", $representative, MappingDisposition::Reused),
            ]);
        }

        if ($mapped !== null || $approved !== null) {
            $id = new WorkId($mapped ?? $approved);
            if ($this->works->find($id) === null) {
                throw $this->failure(
                    CatalogMigrationReason::UnsafeWorkMapping,
                    "Mapped Work target does not exist."
                );
            }
            return MigrationRecordOutcome::mapped([
                $this->mapping("work", $id->value(), MappingDisposition::Reused),
            ]);
        }

        $work = new Work($this->ids->nextWorkId(), $plan->title());
        $this->works->add($work);
        return MigrationRecordOutcome::mapped([
            $this->mapping("work", $work->id()->value(), MappingDisposition::Created),
        ]);
    }

    public function applyEdition(
        SourceObservation $observation,
        CatalogEditionPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $workId = new WorkId($this->requireMappedTarget(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        ));
        if ($this->works->find($workId) === null) {
            throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Mapped Work dependency does not exist."
            );
        }

        $mapped = $this->mappedTarget(
            $run,
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "edition"
        );
        $approved = $plan->approvedExistingEditionId()?->value();
        if ($mapped !== null && $approved !== null && $mapped !== $approved) {
            throw $this->failure(
                CatalogMigrationReason::DivergentReplay,
                "Edition mapping conflicts with the approved canonical target."
            );
        }
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                $observation
            );
        }

        $identity = CanonicalIsbnIdentity::fromMetadata($plan->isbnMetadata());

        if ($plan->aliasOfSourceId() !== null) {
            $representative = new EditionId($this->requireMappedTarget(
                $run,
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                $plan->aliasOfSourceId(),
                "edition"
            ));
            if ($mapped !== null && $mapped !== $representative->value()) {
                throw $this->failure(
                    CatalogMigrationReason::DivergentReplay,
                    "Edition alias conflicts with its representative source mapping."
                );
            }
            $edition = $this->requireCompatibleEdition(
                $representative,
                $workId,
                $plan->isbnMetadata()
            );
            $mappings = [
                $this->mapping(
                    "edition",
                    $edition->id()->value(),
                    MappingDisposition::Reused
                ),
            ];
            if ($identity !== null) {
                $claimed = $this->isbnClaims->findByCanonicalIsbn13(
                    $identity->isbn13()
                );
                if ($claimed === null || !$claimed->equals($edition->id())) {
                    throw $this->failure(
                        CatalogMigrationReason::IsbnConflict,
                        "Edition alias canonical ISBN does not resolve to its representative."
                    );
                }
                $mappings[] = $this->mapping(
                    "canonical_isbn",
                    $identity->isbn13()->value(),
                    MappingDisposition::Reused
                );
            }
            return MigrationRecordOutcome::mapped($mappings);
        }

        $edition = null;
        $editionDisposition = MappingDisposition::Reused;
        $claimDisposition = MappingDisposition::Reused;

        if ($mapped !== null || $approved !== null) {
            $edition = $this->requireCompatibleEdition(
                new EditionId($mapped ?? $approved),
                $workId,
                $plan->isbnMetadata()
            );
        } elseif ($identity !== null) {
            $resolved = $this->editionResolver->resolveIdentity($identity);
            if ($resolved->type() === LocalEditionResolutionType::LocalAmbiguous) {
                throw $this->failure(
                    CatalogMigrationReason::IsbnConflict,
                    "Canonical ISBN resolves ambiguously."
                );
            }
            if ($resolved->type() === LocalEditionResolutionType::LocalExact) {
                $edition = $resolved->requireEdition();
                if (!$edition->workId()->equals($workId)) {
                    throw $this->failure(
                        CatalogMigrationReason::WorkEditionConflict,
                        "Canonical ISBN winner belongs to another Work."
                    );
                }
            }
        }

        if ($edition === null) {
            $edition = new Edition(
                $this->ids->nextEditionId(),
                $workId,
                $plan->title(),
                $plan->isbnMetadata()
            );
            $this->editions->add($edition);
            $editionDisposition = MappingDisposition::Created;
        }

        $mappings = [
            $this->mapping(
                "edition",
                $edition->id()->value(),
                $editionDisposition
            ),
        ];
        if ($identity !== null) {
            $claimBefore = $this->isbnClaims->findByCanonicalIsbn13(
                $identity->isbn13()
            );
            if ($claimBefore === null) {
                try {
                    $this->isbnClaims->claim($identity->isbn13(), $edition->id());
                    $claimDisposition = MappingDisposition::Created;
                } catch (CanonicalIsbnAlreadyClaimed $exception) {
                    throw $this->failure(
                        CatalogMigrationReason::IsbnConflict,
                        "Canonical ISBN was claimed concurrently.",
                        $exception
                    );
                }
            } elseif (!$claimBefore->equals($edition->id())) {
                throw $this->failure(
                    CatalogMigrationReason::IsbnConflict,
                    "Canonical ISBN is claimed by another Edition."
                );
            }
            $mappings[] = $this->mapping(
                "canonical_isbn",
                $identity->isbn13()->value(),
                $claimDisposition
            );
        }

        return MigrationRecordOutcome::mapped($mappings);
    }

    public function applyItem(
        SourceObservation $observation,
        CatalogItemPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->run($observation);
        $libraryId = $plan->targetLibraryId();
        if (!$run->targetLibraryId()->equals($libraryId)) {
            throw $this->failure(
                CatalogMigrationReason::CrossLibraryTarget,
                "Item plan does not target the migration Library."
            );
        }
        $this->validateItemPlan($plan);
        $editionId = new EditionId($this->requireMappedTarget(
            $run,
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            $plan->editionSourceId(),
            "edition"
        ));
        $edition = $this->editions->find($editionId);
        if ($edition === null) {
            throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Mapped Edition dependency does not exist."
            );
        }

        $mapped = $this->mappedTarget(
            $run,
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId(),
            "item"
        );
        $approved = $plan->approvedExistingItemId()?->value();
        if ($mapped !== null && $approved !== null && $mapped !== $approved) {
            throw $this->failure(
                CatalogMigrationReason::DivergentReplay,
                "Item mapping conflicts with the approved target."
            );
        }
        if ($mapped !== null) {
            $this->assertCommittedMappingMatchesPayload(
                $run,
                CatalogItemMigrationParticipant::SOURCE_TYPE,
                $observation
            );
        }

        $itemDisposition = MappingDisposition::Created;
        $item = null;
        if ($mapped !== null || $approved !== null) {
            $item = $this->items->findForMigration(
                new \Biblio\Core\Catalog\ItemId($mapped ?? $approved)
            );
            if ($item === null) {
                throw $this->failure(
                    CatalogMigrationReason::ItemDuplicateConflict,
                    "Mapped Item target does not exist."
                );
            }
            $this->assertCompatibleItem($item, $libraryId, $editionId, $plan);
            $itemDisposition = MappingDisposition::Reused;
        }

        $context = $this->contexts->find($libraryId, $edition->workId());
        if (
            $context !== null
            && !$context->hasSameClassification($plan->classification())
        ) {
            throw $this->failure(
                CatalogMigrationReason::InvalidClassificationTarget,
                "Existing Library classification conflicts with the plan."
            );
        }

        try {
            $contextResult = $this->contextInitializer->initializeOrReuse(
                $libraryId,
                $edition->workId(),
                $plan->classification()
            );
        } catch (Throwable $exception) {
            if ($exception instanceof CatalogMigrationFailure) {
                throw $exception;
            }
            throw $this->failure(
                CatalogMigrationReason::InvalidClassificationTarget,
                "Classification could not be applied to the target Work.",
                $exception
            );
        }

        if ($item === null) {
            $item = Item::active(
                $this->ids->nextItemId(),
                $libraryId,
                $editionId,
                $plan->inventoryNumber(),
                $plan->locationId()
            );
            try {
                $this->items->add($item);
            } catch (CatalogRecordAlreadyExists $exception) {
                throw $this->failure(
                    CatalogMigrationReason::ItemDuplicateConflict,
                    "Item or Library inventory identity already exists.",
                    $exception
                );
            }
        }

        $details = null;
        $detailsDisposition = MappingDisposition::Created;
        if ($plan->localDetails() !== null) {
            $before = $this->localDetails->find($libraryId, $item->id());
            try {
                $details = $this->localDetailsRecorder->recordForLibrary(
                    $libraryId,
                    $item->id(),
                    $plan->localDetails()
                );
            } catch (Throwable $exception) {
                throw $this->failure(
                    CatalogMigrationReason::InvalidItemLocalDetails,
                    "Item-local details conflict with the target Item.",
                    $exception
                );
            }
            if ($before !== null) {
                $detailsDisposition = MappingDisposition::Reused;
            }
        }

        $mappings = [
            $this->mapping("item", $item->id()->value(), $itemDisposition),
            $this->mapping(
                "library_catalog_context",
                $edition->workId()->value(),
                $contextResult->createdSelection() === null
                    ? MappingDisposition::Reused
                    : MappingDisposition::Created
            ),
        ];
        if ($details !== null) {
            $mappings[] = $this->mapping(
                "item_local_details",
                $item->id()->value(),
                $detailsDisposition
            );
        }

        $preservation = $plan->preservation();
        return $preservation === null
            ? MigrationRecordOutcome::mapped($mappings)
            : MigrationRecordOutcome::preserved(
                $preservation->reason(),
                $preservation->outcomeEvidence(),
                $preservation->sourceEvidenceReference(),
                $mappings
            );
    }

    private function assertCompatibleItem(
        Item $item,
        LibraryId $libraryId,
        EditionId $editionId,
        CatalogItemPlan $plan
    ): void {
        if (!$item->libraryId()->equals($libraryId)) {
            throw $this->failure(
                CatalogMigrationReason::CrossLibraryTarget,
                "Mapped Item belongs to another Library."
            );
        }
        if (!$item->editionId()->equals($editionId)) {
            throw $this->failure(
                CatalogMigrationReason::ItemDuplicateConflict,
                "Mapped Item belongs to another Edition."
            );
        }
        if ($item->status() !== ItemStatus::Active) {
            throw $this->failure(
                CatalogMigrationReason::ArchivedItemConflict,
                "Mapped Item is archived and cannot be reactivated by catalog migration."
            );
        }
        if (
            $item->inventoryNumber()?->value()
                !== $plan->inventoryNumber()?->value()
            || $item->locationId()?->value() !== $plan->locationId()?->value()
        ) {
            throw $this->failure(
                CatalogMigrationReason::DivergentReplay,
                "Mapped Item facts diverge from the typed plan."
            );
        }
        if ($plan->localDetails() !== null) {
            $stored = $this->localDetails->find($libraryId, $item->id());
            if ($stored !== null && !$stored->state()->equals($plan->localDetails())) {
                throw $this->failure(
                    CatalogMigrationReason::DivergentReplay,
                    "Mapped Item-local details diverge from the typed plan."
                );
            }
        }
    }

    private function requireCompatibleEdition(
        EditionId $editionId,
        WorkId $workId,
        EditionIsbnMetadata $plannedMetadata
    ): Edition {
        $edition = $this->editions->find($editionId);
        if ($edition === null) {
            throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Mapped Edition target does not exist."
            );
        }
        if (!$edition->workId()->equals($workId)) {
            throw $this->failure(
                CatalogMigrationReason::WorkEditionConflict,
                "Mapped Edition belongs to another Work."
            );
        }
        $planned = CanonicalIsbnIdentity::fromMetadata($plannedMetadata);
        $stored = CanonicalIsbnIdentity::fromMetadata($edition->isbnMetadata());
        if (
            $plannedMetadata->isExplicitlyWithoutIsbn()
                !== $edition->isbnMetadata()->isExplicitlyWithoutIsbn()
            || $planned?->isbn13()->value() !== $stored?->isbn13()->value()
        ) {
            throw $this->failure(
                CatalogMigrationReason::IsbnConflict,
                "Mapped Edition ISBN state conflicts with the typed plan."
            );
        }
        return $edition;
    }

    private function run(SourceObservation $observation): MigrationRun
    {
        $run = $this->ledger->findRun($observation->runId());
        if ($run === null) {
            throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Migration run is unavailable."
            );
        }
        return $run;
    }

    private function requireMappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType
    ): string {
        return $this->mappedTarget($run, $sourceType, $sourceId, $targetType)
            ?? throw $this->failure(
                CatalogMigrationReason::MissingTargetReference,
                "Required catalog dependency has no committed mapping."
            );
    }

    private function assertCommittedMappingMatchesPayload(
        MigrationRun $run,
        string $sourceType,
        SourceObservation $observation
    ): void {
        foreach ($this->ledger->sourceTargets(
            $run,
            $sourceType,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    CatalogMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a committed catalog mapping."
                );
            }
        }
    }

    private function mappedTarget(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $targetType
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets($run, $sourceType, $sourceId) as $trace) {
            if ($trace->targetType() === $targetType) {
                $targets[$trace->targetId()] = true;
            }
        }
        if (count($targets) > 1) {
            throw $this->failure(
                CatalogMigrationReason::DivergentReplay,
                "Logical source identity has conflicting target mappings."
            );
        }
        return array_key_first($targets);
    }

    private function mapping(
        string $targetType,
        string $targetId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping($targetType, $targetId, $disposition);
    }

    private function failure(
        CatalogMigrationReason $reason,
        string $message,
        ?Throwable $previous = null
    ): CatalogMigrationFailure {
        return new CatalogMigrationFailure($reason, $message, $previous);
    }
}
