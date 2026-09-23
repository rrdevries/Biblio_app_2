<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{FinalSourcePlanningContext, MapperContractInventory, RehearsalContract};
use Biblio\Core\Application\Migration\Runner\{MigrationPlanningTarget, MigrationSourceInspection, MigrationSourceMapper, MigrationSourceMappingResult};
use Biblio\Core\Catalog\Classification\{LibraryBookTypeRepository, LibraryGenreRepository};
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;

/** Candidate composition of existing, code-owned mapper semantics. */
final readonly class CurrentV1RehearsalMapper implements MigrationSourceMapper
{
    public function __construct(
        private FinalSourcePlanningContext $source,
        private LibraryBookTypeRepository $bookTypes,
        private LibraryGenreRepository $genres
    ) {}

    public function adapterId(): string { return CurrentV1SourceAdapter::ADAPTER_ID; }

    public function map(MigrationSourceInspection $inspection, MigrationPlanningTarget $target): MigrationSourceMappingResult
    {
        $bundle = $this->source->bundle->toArray();
        RehearsalContract::equal($bundle["mapper_contract_inventory_digest"], (new MapperContractInventory())->digest(), "mapper_inventory_changed");
        $snapshot = (new CurrentV1FinalSourceSnapshotBuilder())->build($inspection, $this->source->intake->identity());
        RehearsalContract::equal($snapshot->digest(), $bundle["snapshot_digest"], "candidate_population_changed");
        $records = [];
        foreach ($inspection->records() as $record) {
            $records[$record->sourceType() . "\0" . $record->sourceId()] = $record;
        }
        $exceptions = [];
        foreach (["author_exceptions", "occurrence_exceptions"] as $type) {
            $exceptions[$type] = [];
            foreach ($this->source->review[$type] as $approval) {
                $parent = $records[$approval["parent_source_type"] . "\0" . $approval["parent_source_id"]] ?? null;
                RehearsalContract::require($parent !== null && hash_equals($parent->payloadHash(), $approval["payload_hash"]), "special_approval_payload_changed");
                RehearsalContract::require(!isset($exceptions[$type][$approval["source_id"]]), "duplicate_special_approval");
                $reason = CurrentV1AuthorMappingReason::tryFrom($approval["reason"]);
                RehearsalContract::require(in_array($reason, [
                    CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
                    CurrentV1AuthorMappingReason::CompoundAuthorScalar,
                    CurrentV1AuthorMappingReason::InvalidAuthorPlaceholder,
                    CurrentV1AuthorMappingReason::MalformedAuthorScalar,
                ], true), "unsupported_special_approval");
                if ($type === "author_exceptions") {
                    RehearsalContract::require($approval["parent_source_type"] === CurrentV1SourceAdapter::AUTHOR
                        && $approval["source_id"] === $approval["parent_source_id"], "special_approval_identity_mismatch");
                } else {
                    RehearsalContract::require($approval["parent_source_type"] === CurrentV1SourceAdapter::BOOK
                        && str_starts_with($approval["source_id"], "v1.book/" . $approval["parent_source_id"] . "/author-occurrence/"), "special_approval_identity_mismatch");
                }
                $exceptions[$type][$approval["source_id"]] = $reason;
            }
        }
        $manifest = $inspection->package()->manifestDigest();
        $mapper = new CurrentV1CatalogMapper(
            classificationMapper: new CurrentV1ClassificationMapper($this->bookTypes, $this->genres, new CurrentV1ReviewedClassificationContract($manifest)),
            itemLocalMapper: new CurrentV1ItemLocalMapper(new CurrentV1ReviewedItemLocalContract($manifest),
                new CurrentV1ReviewedCopyExclusionContract($manifest, $this->source->review["copy_exclusions"])),
            authorMapper: new CurrentV1AuthorMapper(new CurrentV1ReviewedAuthorContract($manifest, $exceptions["author_exceptions"], $exceptions["occurrence_exceptions"])),
            readingMapper: new CurrentV1ReadingMapper(new CurrentV1ReviewedReadingContract($manifest)),
            noteMapper: new CurrentV1NoteMapper(new CurrentV1ReviewedNoteContract($manifest), new StrictPrivateNoteContentPolicy()),
            assessmentMapper: new CurrentV1AssessmentMapper(new CurrentV1ReviewedAssessmentContract($manifest)),
            seriesMapper: new CurrentV1SeriesMapper(new CurrentV1ReviewedSeriesContract($manifest)),
            containedWorkMapper: new CurrentV1ContainedWorkMapper(new CurrentV1ReviewedContainedWorkContract($manifest)),
            wishlistMapper: new CurrentV1WishlistMapper(new CurrentV1ReviewedWishlistContract($manifest)),
            readingGoalMapper: new CurrentV1ReadingGoalMapper(new CurrentV1ReviewedReadingGoalContract($manifest))
        );
        $mapping = $mapper->map($inspection, $target);
        return new MigrationSourceMappingResult($mapping->records(), $mapping->findings(),
            \Biblio\Core\Application\Migration\Cutover\ReviewedSourceProfile::forPlanningContext($this->source, $inspection));
    }
}
