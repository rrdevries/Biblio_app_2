<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogEditionMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogEditionPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogItemMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogItemPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkPlan;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMapper;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingResult;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Library\LibraryId;

/**
 * CURRENT-specific semantic bridge. The adapter remains a faithful parser and
 * CAT participants remain source-neutral target planners/writers.
 */
final readonly class CurrentV1CatalogMapper implements MigrationSourceMapper
{
    private IsbnCanonicalizer $isbn;

    public function __construct(
        private ?CurrentV1CatalogItemDependencyProvider $itemDependencies = null,
        ?IsbnCanonicalizer $isbn = null,
        private ?CurrentV1ClassificationMapper $classificationMapper = null
    ) {
        $this->isbn = $isbn ?? new IsbnCanonicalizer();
    }

    public function adapterId(): string
    {
        return CurrentV1SourceAdapter::ADAPTER_ID;
    }

    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target
    ): MigrationSourceMappingResult {
        $records = [];
        $books = [];
        $copies = [];
        foreach ($inspection->records() as $record) {
            if ($record->sourceType() === CurrentV1SourceAdapter::BOOK) {
                $books[$this->sourceKey($record->sourceId())] = $record;
                continue;
            }
            if ($record->sourceType() === CurrentV1SourceAdapter::COPY) {
                $copies[$this->sourceKey($record->sourceId())] = $record;
                continue;
            }
            $records[] = $record;
        }
        ksort($books, SORT_STRING);
        ksort($copies, SORT_STRING);

        $findings = [];
        $states = [];
        $isbnGroups = [];
        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $bookKey = $this->sourceKey($bookId);
            $payload = $book->payload();
            $title = $payload["title"] ?? null;
            if (!is_string($title) || trim($title) === "") {
                $states[$bookKey] = ["status" => "quarantined"];
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Quarantined,
                    CurrentV1CatalogMappingReason::MissingRequiredCatalogField
                );
                $this->preservationFindings($book, $findings);
                continue;
            }

            $isbnState = $this->isbnState($payload);
            if ($isbnState["status"] === "invalid") {
                $states[$bookKey] = ["status" => "quarantined"];
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Quarantined,
                    CurrentV1CatalogMappingReason::InvalidIsbn
                );
                $this->preservationFindings($book, $findings);
                continue;
            }
            if ($isbnState["status"] === "conflict") {
                $states[$bookKey] = ["status" => "quarantined"];
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Quarantined,
                    CurrentV1CatalogMappingReason::ConflictingIsbnEvidence
                );
                $this->preservationFindings($book, $findings);
                continue;
            }

            /** @var EditionIsbnMetadata $metadata */
            $metadata = $isbnState["metadata"];
            $canonical = $isbnState["canonical"];
            $states[$bookKey] = [
                "status" => "ready",
                "title" => $title,
                "metadata" => $metadata,
                "canonical" => $canonical,
                "invalid_evidence" => (bool) ($isbnState["invalid_evidence"] ?? false),
                "representative" => $bookId,
            ];
            if (is_string($canonical)) {
                $isbnGroups[$canonical][] = $bookId;
            }
        }

        foreach ($isbnGroups as $memberIds) {
            if (count($memberIds) < 2) {
                continue;
            }
            sort($memberIds, SORT_STRING);
            $titles = [];
            foreach ($memberIds as $memberId) {
                $titles[$states[$this->sourceKey($memberId)]["title"]] = true;
            }
            if (count($titles) !== 1) {
                foreach ($memberIds as $memberId) {
                    $memberKey = $this->sourceKey($memberId);
                    $states[$memberKey] = ["status" => "quarantined"];
                    $findings[] = $this->finding(
                        $books[$memberKey],
                        MigrationDisposition::Quarantined,
                        CurrentV1CatalogMappingReason::DuplicateIsbnWorkConflict
                    );
                    $this->preservationFindings($books[$memberKey], $findings);
                }
                continue;
            }
            $representative = $memberIds[0];
            foreach ($memberIds as $memberId) {
                $states[$this->sourceKey($memberId)]["representative"] = $representative;
            }
        }

        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $bookKey = $this->sourceKey($bookId);
            $state = $states[$bookKey];
            if ($state["status"] !== "ready") {
                continue;
            }
            $representative = $state["representative"];
            $isAlias = $representative !== $bookId;
            $isRepeatedRepresentative = !$isAlias
                && is_string($state["canonical"])
                && count($isbnGroups[$state["canonical"]] ?? []) > 1;
            $workSourceId = CurrentV1CatalogSourceIds::work($bookId);
            $editionSourceId = CurrentV1CatalogSourceIds::edition($bookId);
            $records[] = MigrationSourceRecord::typed(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                $workSourceId,
                new CatalogWorkPlan(
                    $state["title"],
                    aliasOfSourceId: $isAlias
                        ? CurrentV1CatalogSourceIds::work($representative)
                        : null
                )
            );
            $records[] = MigrationSourceRecord::typed(
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                $editionSourceId,
                new CatalogEditionPlan(
                    $workSourceId,
                    $state["title"],
                    $state["metadata"],
                    aliasOfSourceId: $isAlias
                        ? CurrentV1CatalogSourceIds::edition($representative)
                        : null
                ),
                [CatalogWorkMigrationParticipant::SOURCE_TYPE . ":" . $workSourceId]
            );

            $reason = $isAlias
                ? CurrentV1CatalogMappingReason::DuplicateIsbnAlias
                : ($isRepeatedRepresentative
                    ? CurrentV1CatalogMappingReason::DuplicateIsbnRepresentative
                    : ($state["canonical"] === null
                        ? CurrentV1CatalogMappingReason::UnknownIsbn
                        : CurrentV1CatalogMappingReason::CatalogWorkEditionPlanned));
            $findings[] = $this->finding(
                $book,
                $isAlias ? MigrationDisposition::Transformed : MigrationDisposition::Mapped,
                $reason,
                [
                    [
                        "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                        "source_id" => $workSourceId,
                    ],
                    [
                        "source_type" => CatalogEditionMigrationParticipant::SOURCE_TYPE,
                        "source_id" => $editionSourceId,
                    ],
                ]
            );
            $this->preservationFindings($book, $findings);
            if ($state["invalid_evidence"] === true) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::InvalidIsbnEvidencePreserved
                );
            }
            $payload = $book->payload();
            $conservative = is_string($state["canonical"])
                && count($isbnGroups[$state["canonical"]] ?? []) === 1
                && ($payload["editionFormat"] ?? null) === "standaard"
                && !$this->nonEmpty($payload["variantOfBookId"] ?? null)
                && (!is_array($payload["containedWorks"] ?? null)
                    || $payload["containedWorks"] === []);
            $states[$bookKey]["conservative"] = $conservative;
            if ($conservative) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Mapped,
                    CurrentV1CatalogMappingReason::ConservativeCatalogSubset
                );
            }
        }

        $classification = null;
        if ($this->classificationMapper !== null) {
            $representatives = [];
            foreach ($books as $book) {
                $state = $states[$this->sourceKey($book->sourceId())] ?? null;
                if (($state["status"] ?? null) === "ready") {
                    $representatives[$book->sourceId()] = $state["representative"];
                }
            }
            $candidateBooks = [];
            foreach ($copies as $copy) {
                $bookId = $copy->payload()["bookId"] ?? null;
                if (
                    is_string($bookId)
                    && ($states[$this->sourceKey($bookId)]["status"] ?? null) === "ready"
                ) {
                    $candidateBooks[$bookId] = true;
                }
            }
            $classification = $this->classificationMapper->map(
                $inspection,
                $target,
                $books,
                $representatives,
                $candidateBooks
            );
            array_push($findings, ...$classification->findings());
        }

        foreach ($copies as $copy) {
            $copyId = $copy->sourceId();
            $bookId = $copy->payload()["bookId"] ?? null;
            $bookKey = is_string($bookId) ? $this->sourceKey($bookId) : null;
            if ($bookKey === null || !isset($books[$bookKey])) {
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::Quarantined,
                    CurrentV1CatalogMappingReason::OrphanCopyReference
                );
                continue;
            }
            if (($states[$bookKey]["status"] ?? null) !== "ready") {
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::Quarantined,
                    CurrentV1CatalogMappingReason::CatalogBookQuarantined
                );
                $this->copyPreservationFindings($copy, $findings);
                continue;
            }

            $dependencies = $this->itemDependencies?->forCopy(
                $copy,
                $books[$bookKey],
                $target
            ) ?? CurrentV1CatalogItemDependencies::unresolved();
            if ($classification !== null) {
                $dependencies = new CurrentV1CatalogItemDependencies(
                    $classification->selection($bookId),
                    $dependencies->itemLocalReviewed(),
                    $dependencies->localDetails()
                );
            }
            $blocked = false;
            if ($dependencies->classification() === null) {
                $blocked = true;
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::UnresolvedClassificationDependency
                );
            }
            if (!$dependencies->itemLocalReviewed()) {
                $blocked = true;
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::UnresolvedItemLocalDependency
                );
            }
            if (!$blocked) {
                $itemSourceId = CurrentV1CatalogSourceIds::item($copyId);
                $editionSourceId = CurrentV1CatalogSourceIds::edition($bookId);
                $records[] = MigrationSourceRecord::typed(
                    CatalogItemMigrationParticipant::SOURCE_TYPE,
                    $itemSourceId,
                    new CatalogItemPlan(
                        $editionSourceId,
                        new LibraryId($target->libraryId()),
                        $dependencies->classification(),
                        null,
                        null,
                        $dependencies->localDetails()
                    ),
                    [CatalogEditionMigrationParticipant::SOURCE_TYPE . ":" . $editionSourceId]
                );
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::Mapped,
                    CurrentV1CatalogMappingReason::CatalogItemPlanned,
                    [[
                        "source_type" => CatalogItemMigrationParticipant::SOURCE_TYPE,
                        "source_id" => $itemSourceId,
                    ]]
                );
            }
            if (($states[$bookKey]["conservative"] ?? false) === true) {
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::ConservativeItemSubset
                );
            }
            $this->copyPreservationFindings($copy, $findings);
        }

        return new MigrationSourceMappingResult($records, $findings);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   status:string,
     *   metadata?:EditionIsbnMetadata,
     *   canonical?:?string,
     *   invalid_evidence?:bool
     * }
     */
    private function isbnState(array $payload): array
    {
        $raw = [];
        $invalid = false;
        $identities = [];
        foreach (["isbn", "isbn10", "isbn13"] as $field) {
            $value = $payload[$field] ?? null;
            if ($value === null || $value === "") {
                continue;
            }
            if (!is_string($value) || trim($value) === "") {
                $invalid = true;
                $raw[] = "invalid";
                continue;
            }
            $raw[] = $value;
            $parsed = $this->isbn->parse($value);
            if (!$parsed->isValid()) {
                $invalid = true;
                continue;
            }
            $identity = $parsed->identity();
            if ($identity instanceof CanonicalIsbnIdentity) {
                $identities[$identity->isbn13()->value()] = $identity;
            }
        }
        if (count($identities) > 1) {
            return ["status" => "conflict"];
        }
        if ($identities === []) {
            if ($raw !== []) {
                return ["status" => "invalid"];
            }
            return [
                "status" => "ready",
                "metadata" => EditionIsbnMetadata::unknown(),
                "canonical" => null,
            ];
        }
        /** @var CanonicalIsbnIdentity $identity */
        $identity = array_values($identities)[0];
        return [
            "status" => "ready",
            "metadata" => $identity->metadata(),
            "canonical" => $identity->isbn13()->value(),
            "invalid_evidence" => $invalid,
        ];
    }

    /**
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function preservationFindings(
        MigrationSourceRecord $book,
        array &$findings
    ): void {
        $payload = $book->payload();
        if ($this->nonEmpty($payload["variantOfBookId"] ?? null)) {
            $findings[] = $this->finding(
                $book,
                MigrationDisposition::PreservedDeferred,
                CurrentV1CatalogMappingReason::DeferredVariantRelation
            );
        }
        if (is_array($payload["containedWorks"] ?? null) && $payload["containedWorks"] !== []) {
            $findings[] = $this->finding(
                $book,
                MigrationDisposition::PreservedDeferred,
                CurrentV1CatalogMappingReason::DeferredContainedWork,
                [],
                count($payload["containedWorks"])
            );
        }
        foreach ([
            "publisher", "publishDate", "language", "pages", "binding",
            "editionFormat", "description", "coverUrl", "platformSource",
            "googleLink", "specialFeatures", "provenance",
        ] as $field) {
            if ($this->nonEmpty($payload[$field] ?? null)) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::DeferredEditionEvidence
                );
                break;
            }
        }
        $findings[] = $this->finding(
            $book,
            MigrationDisposition::PreservedDeferred,
            CurrentV1CatalogMappingReason::CatalogSourceEvidenceRetained
        );
    }

    /** @param list<MigrationSourceMappingFinding> $findings */
    private function copyPreservationFindings(
        MigrationSourceRecord $copy,
        array &$findings
    ): void {
        $payload = $copy->payload();
        foreach (["copyNumber", "legacyBookNumber", "sourceBookNumber"] as $field) {
            if ($this->nonEmpty($payload[$field] ?? null)) {
                $findings[] = $this->finding(
                    $copy,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1CatalogMappingReason::DeferredItemSourceNumbers
                );
                break;
            }
        }
    }

    private function nonEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== "";
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && $value !== false;
    }

    private function sourceKey(string $sourceId): string
    {
        return "source:" . $sourceId;
    }

    /**
     * @param list<array{source_type:string,source_id:string}> $plannedIdentities
     */
    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1CatalogMappingReason $reason,
        array $plannedIdentities = [],
        int $occurrenceCount = 1
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $record->sourceType(),
            $record->sourceId(),
            $disposition,
            $reason->value,
            $plannedIdentities,
            $occurrenceCount
        );
    }
}
