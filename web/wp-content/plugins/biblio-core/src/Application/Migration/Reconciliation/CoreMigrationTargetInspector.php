<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditKey,
    AuthorContributorCreditId,
    AuthorContributorCreditRepository,
    AuthorContributorCreditStatus
};
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationItemRepository;
use Biblio\Core\Application\Migration\Catalog\{
    CatalogEditionMigrationParticipant,
    CatalogEditionPlan,
    CatalogItemMigrationParticipant,
    CatalogItemPlan,
    CatalogWorkMigrationParticipant,
    CatalogWorkPlan
};
use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Notes\PrivateNotePlan;
use Biblio\Core\Application\Migration\Reading\ReadingRoundPlan;
use Biblio\Core\Application\Migration\MigrationLedgerObservation;
use Biblio\Core\Application\Migration\MigrationLedgerSnapshot;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
use Biblio\Core\Catalog\{
    AuthorId,
    AuthorRepository,
    CanonicalIsbnIdentity,
    EditionId,
    EditionIdentifierClaimRepository,
    EditionRepository,
    Isbn13,
    ItemId,
    ItemStatus,
    ItemLocalDetailsRepository,
    WorkId,
    WorkRepository
};
use Biblio\Core\Notes\{PrivateNoteId, PrivateNoteRepository};
use Biblio\Core\Reading\{
    ReadingRoundId,
    ReadingRoundProvenance,
    ReadingRoundRepository,
    ReadingSource
};
use Throwable;

final readonly class CoreMigrationTargetInspector implements MigrationTargetInspector
{
    public function __construct(
        private WorkRepository $works,
        private EditionRepository $editions,
        private CatalogMigrationItemRepository $items,
        private EditionIdentifierClaimRepository $isbnClaims,
        private LibraryCatalogContextRepository $catalogContexts,
        private ItemLocalDetailsRepository $itemLocalDetails,
        private AuthorRepository $authors,
        private AuthorContributorCreditRepository $authorCredits,
        private ReadingRoundRepository $readingRounds,
        private PrivateNoteRepository $privateNotes
    ) {
    }

    public function exists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        MigrationTargetMapping $mapping,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        try {
            return match ($mapping->targetType()) {
                "work" => $this->workExists($record, $mapping->targetId()),
                "edition" => $this->editionExists($record, $mapping->targetId(), $snapshot),
                "item" => $this->itemExists($run, $record, $mapping->targetId(), $snapshot),
                "canonical_isbn" => $this->isbnClaimExists(
                    $record,
                    $observation,
                    $mapping->targetId()
                ),
                "library_catalog_context" => $this->catalogContextExists(
                    $run,
                    $record,
                    $mapping->targetId(),
                    $snapshot
                ),
                "item_local_details" => $this->itemLocalDetailsExists(
                    $run,
                    $record,
                    $observation,
                    $mapping->targetId()
                ),
                "author" => $this->authorExists($record, $mapping->targetId()),
                "author_contributor_credit" => $this->authorCreditExists(
                    $record,
                    $mapping->targetId(),
                    $snapshot
                ),
                "work_contributor" => $this->workContributorExists(
                    $record,
                    $observation,
                    $mapping->targetId(),
                    $snapshot
                ),
                "reading_round" => $this->readingRoundExists(
                    $run,
                    $record,
                    $mapping->targetId(),
                    $snapshot
                ),
                "private_note" => $this->privateNoteExists(
                    $run,
                    $record,
                    $mapping->targetId(),
                    $snapshot
                ),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function workExists(
        MigrationSourceRecord $record,
        string $targetId
    ): bool {
        $plan = $record->typedPlan();
        return $plan instanceof CatalogWorkPlan
            && ($plan->approvedExistingWorkId() === null
                || $plan->approvedExistingWorkId()->value() === $targetId)
            && $this->works->find(new WorkId($targetId)) !== null;
    }

    private function authorExists(
        MigrationSourceRecord $record,
        string $targetId
    ): bool {
        $plan = $record->typedPlan();
        return $plan instanceof CatalogAuthorPlan
            && ($plan->approvedExistingAuthorId() === null
                || $plan->approvedExistingAuthorId()->value() === $targetId)
            && $this->authors->find(new AuthorId($targetId)) !== null;
    }

    private function editionExists(
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogEditionPlan) {
            return false;
        }
        $workId = $this->dependencyMappingId(
            $snapshot,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        );
        $edition = $this->editions->find(new EditionId($targetId));
        $plannedIsbn = CanonicalIsbnIdentity::fromMetadata($plan->isbnMetadata());
        $storedIsbn = $edition === null
            ? null
            : CanonicalIsbnIdentity::fromMetadata($edition->isbnMetadata());
        return $workId !== null
            && $edition !== null
            && ($plan->approvedExistingEditionId() === null
                || $plan->approvedExistingEditionId()->value() === $targetId)
            && $edition->workId()->value() === $workId
            && $plan->isbnMetadata()->isExplicitlyWithoutIsbn()
                === $edition->isbnMetadata()->isExplicitlyWithoutIsbn()
            && $plannedIsbn?->isbn13()->value()
                === $storedIsbn?->isbn13()->value();
    }

    private function itemExists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool
    {
        $plan = $record->typedPlan();
        if (
            !$plan instanceof CatalogItemPlan
            || !$plan->targetLibraryId()->equals($run->targetLibraryId())
        ) {
            return false;
        }
        $editionId = $this->dependencyMappingId(
            $snapshot,
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            $plan->editionSourceId(),
            "edition"
        );
        $item = $this->items->findForMigration(new ItemId($targetId));
        return $editionId !== null
            && $item !== null
            && ($plan->approvedExistingItemId() === null
                || $plan->approvedExistingItemId()->value() === $targetId)
            && $item->libraryId()->equals($run->targetLibraryId())
            && $item->editionId()->value() === $editionId
            && $item->status() === ItemStatus::Active
            && $item->inventoryNumber()?->value()
                === $plan->inventoryNumber()?->value()
            && $item->locationId()?->value() === $plan->locationId()?->value();
    }

    private function catalogContextExists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogItemPlan) {
            return false;
        }
        $editionId = $this->dependencyMappingId(
            $snapshot,
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            $plan->editionSourceId(),
            "edition"
        );
        $edition = $editionId === null
            ? null
            : $this->editions->find(new EditionId($editionId));
        if ($edition === null || $edition->workId()->value() !== $targetId) {
            return false;
        }
        $context = $this->catalogContexts->find(
            $run->targetLibraryId(),
            $edition->workId()
        );
        return $context !== null
            && $context->hasSameClassification($plan->classification());
    }

    private function itemLocalDetailsExists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        string $targetId
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogItemPlan || $plan->localDetails() === null) {
            return false;
        }
        $itemId = $this->mappingId($observation, "item");
        $details = $itemId === null || $targetId !== $itemId
            ? null
            : $this->itemLocalDetails->find(
                $run->targetLibraryId(),
                new ItemId($targetId)
            );
        return $details !== null
            && $details->state()->equals($plan->localDetails());
    }

    private function isbnClaimExists(
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        string $isbn
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogEditionPlan) {
            return false;
        }
        $planned = CanonicalIsbnIdentity::fromMetadata($plan->isbnMetadata());
        $edition = $this->mappingId($observation, "edition");
        if ($edition === null || $planned?->isbn13()->value() !== $isbn) {
            return false;
        }
        return $this->isbnClaims->findByCanonicalIsbn13(new Isbn13($isbn))
            ?->equals(new EditionId($edition)) === true;
    }

    private function workContributorExists(
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogWorkContributorPlan) {
            return false;
        }
        $workId = $this->dependencyMappingId(
            $snapshot,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        );
        $authorId = $this->dependencyMappingId(
            $snapshot,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            $plan->authorSourceId(),
            "author"
        );
        $creditId = $this->mappingId($observation, "author_contributor_credit");
        if ($creditId === null || $workId === null || $authorId === null) {
            return false;
        }
        $credit = $this->authorCredits->find(new AuthorContributorCreditId($creditId));
        if (
            $credit === null
            || $credit->status() !== AuthorContributorCreditStatus::Linked
            || $credit->authorId()?->value() !== $authorId
            || $credit->workId()->value() !== $workId
            || $credit->role() !== $plan->role()
            || $credit->position()->value() !== $plan->position()->value()
            || AuthorContributorCreditKey::normalizeObservedName(
                $credit->observedDisplayName()
            ) !== $plan->observedDisplayName()
        ) {
            return false;
        }
        $expected = "work-contributor-" . hash("sha256", implode("\0", [
            "work-contributor-v1",
            $credit->workId()->value(),
            $credit->authorId()->value(),
            $credit->role()->value,
            (string) $credit->position()->value(),
        ]));
        if ($targetId !== $expected) {
            return false;
        }
        foreach (
            $this->authors->contributorsForWorks([$credit->workId()])[$credit->workId()->value()] ?? []
            as $edge
        ) {
            if (
                $edge->authorId()->value() === $credit->authorId()->value()
                && $edge->role() === $credit->role()
                && $edge->position()->value() === $credit->position()->value()
            ) {
                return true;
            }
        }
        return false;
    }

    private function authorCreditExists(
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (!$plan instanceof CatalogWorkContributorPlan) {
            return false;
        }
        $workId = $this->dependencyMappingId(
            $snapshot,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        );
        $authorId = $this->dependencyMappingId(
            $snapshot,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            $plan->authorSourceId(),
            "author"
        );
        $credit = $this->authorCredits->find(
            new AuthorContributorCreditId($targetId)
        );
        return $workId !== null
            && $authorId !== null
            && $credit !== null
            && $credit->status() === AuthorContributorCreditStatus::Linked
            && $credit->workId()->value() === $workId
            && $credit->authorId()?->value() === $authorId
            && $credit->role() === $plan->role()
            && $credit->position()->value() === $plan->position()->value()
            && AuthorContributorCreditKey::normalizeObservedName(
                $credit->observedDisplayName()
            ) === $plan->observedDisplayName();
    }

    private function readingRoundExists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (
            !$plan instanceof ReadingRoundPlan
            || !$plan->targetUserId()->equals($run->targetUserId())
        ) {
            return false;
        }
        $workId = $this->dependencyMappingId(
            $snapshot,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        );
        $itemId = $plan->itemSourceId() === null ? null : $this->dependencyMappingId(
            $snapshot,
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            $plan->itemSourceId(),
            "item"
        );
        $round = $this->readingRounds->findForUser(
            new ReadingRoundId($targetId),
            $run->targetUserId()
        );
        $source = $itemId === null ? null : ReadingSource::libraryItem(new ItemId($itemId));
        return $workId !== null
            && ($plan->itemSourceId() === null || $itemId !== null)
            && $round !== null
            && $round->workId()->value() === $workId
            && ReadingSource::same($round->source(), $source)
            && $round->outcome() === $plan->outcome()
            && $round->period()->equals($plan->period())
            && $round->provenance() === ReadingRoundProvenance::MigrationImported;
    }

    private function privateNoteExists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        string $targetId,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        $plan = $record->typedPlan();
        if (
            !$plan instanceof PrivateNotePlan
            || !$plan->targetUserId()->equals($run->targetUserId())
        ) {
            return false;
        }
        $workId = $this->dependencyMappingId(
            $snapshot,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId(),
            "work"
        );
        $roundId = $plan->readingRoundSourceId() === null
            ? null
            : $this->dependencyMappingId(
                $snapshot,
                \Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant::SOURCE_TYPE,
                $plan->readingRoundSourceId(),
                "reading_round"
            );
        $note = $this->privateNotes->findForUser(
            new PrivateNoteId($targetId),
            $run->targetUserId()
        );
        return $workId !== null
            && ($plan->readingRoundSourceId() === null || $roundId !== null)
            && $note !== null
            && $note->workId()->value() === $workId
            && $note->readingRoundId()?->value() === $roundId
            && $note->content()->equals($plan->content())
            && $note->createdAt() == $plan->createdAt()
            && $note->updatedAt() == $plan->updatedAt()
            && $note->version()->value() === 1;
    }

    private function mappingId(
        MigrationLedgerObservation $observation,
        string $targetType
    ): ?string {
        $ids = [];
        foreach ($observation->mappings() as $mapping) {
            if ($mapping->targetType() === $targetType) {
                $ids[$mapping->targetId()] = true;
            }
        }
        return count($ids) === 1 ? array_key_first($ids) : null;
    }

    private function dependencyMappingId(
        MigrationLedgerSnapshot $snapshot,
        string $sourceType,
        string $sourceId,
        string $targetType
    ): ?string {
        $ids = [];
        foreach ($snapshot->observations() as $observation) {
            if (
                $observation->sourceType() !== $sourceType
                || $observation->sourceId() !== $sourceId
            ) {
                continue;
            }
            foreach ($observation->mappings() as $mapping) {
                if ($mapping->targetType() === $targetType) {
                    $ids[$mapping->targetId()] = true;
                }
            }
        }
        return count($ids) === 1 ? array_key_first($ids) : null;
    }
}
