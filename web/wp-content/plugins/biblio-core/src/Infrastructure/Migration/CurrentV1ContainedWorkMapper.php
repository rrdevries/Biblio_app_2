<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogWorkContainmentMigrationParticipant,
    CatalogWorkContainmentPlan,
    CatalogWorkMigrationParticipant,
    CatalogWorkPlan
};
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord
};
use Biblio\Core\Application\Migration\Series\{
    CatalogSeriesMigrationParticipant,
    CatalogWorkSeriesMigrationParticipant,
    CatalogWorkSeriesPlan
};
use Biblio\Core\Catalog\{
    ContainmentPosition,
    ContributorPosition,
    ContributorRole,
    IsbnCanonicalizer,
    SeriesPosition
};
use Biblio\Core\Exception\ValidationException;

/** Implements only the reviewed CURRENT contained-work semantics from docs/139. */
final readonly class CurrentV1ContainedWorkMapper
{
    private IsbnCanonicalizer $isbn;

    public function __construct(
        private CurrentV1ReviewedContainedWorkContract $contract =
            new CurrentV1ReviewedContainedWorkContract(),
        ?IsbnCanonicalizer $isbn = null
    ) {
        $this->isbn = $isbn ?? new IsbnCanonicalizer();
    }

    /**
     * @param array<string,MigrationSourceRecord> $books
     * @param array<string,string> $workRepresentatives
     */
    public function map(
        MigrationSourceInspection $inspection,
        array $books,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT contained-work contract does not match the source manifest."
            );
        }

        $baseSeries = [];
        $positiveLists = [];
        foreach ($books as $book) {
            $payload = $book->payload();
            $bookId = $book->sourceId();
            if (($payload["id"] ?? null) !== $bookId) {
                throw $this->unsupported(
                    "CURRENT V1 Book identity and contained-work parent disagree."
                );
            }
            $seriesName = $payload["seriesName"] ?? null;
            if (is_string($seriesName) && $seriesName !== "") {
                $baseSeries[CurrentV1SeriesSourceIds::series($seriesName)] = true;
            }
            $contained = $payload["containedWorks"] ?? null;
            if (!is_array($contained) || !array_is_list($contained)) {
                throw $this->unsupported(
                    "CURRENT V1 containedWorks must be a reviewed list."
                );
            }
            if ($contained !== [] && isset($workRepresentatives[$bookId])) {
                $positiveLists[$workRepresentatives[$bookId]][] = $bookId;
            }
        }

        $blockedBooks = [];
        $findings = [];
        foreach ($positiveLists as $representative => $bookIds) {
            if (count($bookIds) < 2) {
                continue;
            }
            sort($bookIds, SORT_STRING);
            foreach ($bookIds as $bookId) {
                $blockedBooks[$bookId] = true;
            }
            $findings[] = new MigrationSourceMappingFinding(
                "v1.contained_work_alias_conflict",
                "v1.book/{$representative}/contained-work/alias-conflict",
                MigrationDisposition::Quarantined,
                CurrentV1ContainedWorkMappingReason::AliasConflict->value,
                occurrenceCount: count($bookIds)
            );
        }

        $records = [];
        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $contained = $book->payload()["containedWorks"];
            if ($contained === []) {
                continue;
            }
            if (isset($blockedBooks[$bookId])) {
                continue;
            }
            if (!isset($workRepresentatives[$bookId])) {
                $findings[] = new MigrationSourceMappingFinding(
                    CurrentV1SourceAdapter::BOOK,
                    "v1.book/{$bookId}/contained-work",
                    MigrationDisposition::Quarantined,
                    CurrentV1ContainedWorkMappingReason::UnresolvedParent->value,
                    occurrenceCount: count($contained)
                );
                continue;
            }

            foreach ($contained as $offset => $row) {
                $slot = $offset + 1;
                $this->assertRow($row);
                /** @var array{author:string,isbn:string,series:string,seriesIndex:string,title:string} $row */
                $workSourceId = CurrentV1ContainedWorkSourceIds::work(
                    $bookId,
                    $slot
                );
                try {
                    $workRecord = MigrationSourceRecord::typed(
                        CatalogWorkMigrationParticipant::SOURCE_TYPE,
                        $workSourceId,
                        new CatalogWorkPlan($row["title"])
                    );
                } catch (ValidationException $exception) {
                    throw $this->unsupported(
                        "CURRENT V1 contained Work title is invalid.",
                        $exception
                    );
                }
                $records[] = $workRecord;
                $findings[] = $this->finding(
                    $workSourceId,
                    CurrentV1ContainedWorkMappingReason::ChildWorkPlanned,
                    [$this->identity($workRecord)]
                );

                $parentSourceId = CurrentV1CatalogSourceIds::work($bookId);
                $containmentSourceId =
                    CurrentV1ContainedWorkSourceIds::containment($bookId, $slot);
                $containment = MigrationSourceRecord::typed(
                    CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE,
                    $containmentSourceId,
                    new CatalogWorkContainmentPlan(
                        $parentSourceId,
                        $workSourceId,
                        new ContainmentPosition($slot),
                        $this->contract->identity()
                    ),
                    [
                        CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                            . $parentSourceId,
                        CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                            . $workSourceId,
                    ]
                );
                $records[] = $containment;
                $findings[] = $this->finding(
                    $containmentSourceId,
                    CurrentV1ContainedWorkMappingReason::ContainmentPlanned,
                    [$this->identity($containment)]
                );

                $this->mapAuthor(
                    $bookId,
                    $slot,
                    $row["author"],
                    $workSourceId,
                    $records,
                    $findings
                );
                $this->mapSeries(
                    $inspection,
                    $bookId,
                    $slot,
                    $row["series"],
                    $row["seriesIndex"],
                    $workSourceId,
                    $baseSeries,
                    $records,
                    $findings
                );
                $this->mapIsbn(
                    $inspection,
                    $bookId,
                    $slot,
                    $row["isbn"],
                    $records,
                    $findings
                );
            }
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.contained_work_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1ContainedWorkMappingReason::MappingContractApplied->value
        );
        return new MigrationSourceMappingResult($records, $findings);
    }

    /** @param mixed $row */
    private function assertRow(mixed $row): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw $this->unsupported(
                "CURRENT V1 contained Work must be a reviewed object."
            );
        }
        $keys = array_keys($row);
        $expected = ["author", "isbn", "series", "seriesIndex", "title"];
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->unsupported(
                "CURRENT V1 contained Work fields differ from the reviewed shape."
            );
        }
        foreach ($expected as $field) {
            if (!is_string($row[$field])) {
                throw $this->unsupported(
                    "CURRENT V1 contained Work field type is unsupported."
                );
            }
        }
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function mapAuthor(
        string $bookId,
        int $slot,
        string $rawName,
        string $workSourceId,
        array &$records,
        array &$findings
    ): void {
        if (trim($rawName) === "") {
            return;
        }
        try {
            $name = AuthorContributorCreditKey::normalizeObservedName($rawName);
            $authorSourceId = CurrentV1ContainedWorkSourceIds::author(
                $bookId,
                $slot,
                $name
            );
            $author = MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                $authorSourceId,
                new CatalogAuthorPlan($name)
            );
        } catch (ValidationException $exception) {
            throw $this->unsupported(
                "CURRENT V1 contained Author name is invalid.",
                $exception
            );
        }
        $records[] = $author;
        $findings[] = $this->finding(
            $authorSourceId,
            CurrentV1ContainedWorkMappingReason::AuthorPlanned,
            [$this->identity($author)]
        );

        $occurrenceSourceId =
            CurrentV1ContainedWorkSourceIds::authorOccurrence($bookId, $slot);
        $contributor = MigrationSourceRecord::typed(
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            $occurrenceSourceId,
            new CatalogWorkContributorPlan(
                $authorSourceId,
                $workSourceId,
                ContributorRole::Author,
                new ContributorPosition(1),
                $name
            ),
            [
                CatalogAuthorMigrationParticipant::SOURCE_TYPE . ":"
                    . $authorSourceId,
                CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                    . $workSourceId,
            ]
        );
        $records[] = $contributor;
        $findings[] = $this->finding(
            $occurrenceSourceId,
            CurrentV1ContainedWorkMappingReason::ContributorPlanned,
            [$this->identity($contributor)]
        );
    }

    /**
     * @param array<string,true> $baseSeries
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function mapSeries(
        MigrationSourceInspection $inspection,
        string $bookId,
        int $slot,
        string $name,
        string $index,
        string $workSourceId,
        array $baseSeries,
        array &$records,
        array &$findings
    ): void {
        if ($name === "" && $index === "") {
            return;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $index) !== 1) {
            throw $this->unsupported(
                "CURRENT V1 contained Series position is unsupported."
            );
        }
        $sourceId = CurrentV1ContainedWorkSourceIds::series($bookId, $slot);
        $envelope = [
            "source_slot" => $sourceId,
            "one_based_slot" => $slot,
            "series" => $name,
            "series_index" => $index,
        ];
        if ($name === "") {
            $preserved = $this->preservation(
                $inspection,
                $sourceId,
                "current_v1_contained_work_series",
                CurrentV1ContainedWorkMappingReason::SeriesNameMissing->value,
                $bookId,
                "containedWorks",
                $envelope,
                $this->contract->identity()
            );
            $records[] = $preserved;
            $findings[] = $this->finding(
                $sourceId,
                CurrentV1ContainedWorkMappingReason::SeriesNameMissing,
                [$this->identity($preserved)],
                $this->evidenceHash($preserved),
                MigrationDisposition::PreservedDeferred
            );
            return;
        }

        $seriesSourceId = CurrentV1SeriesSourceIds::series($name);
        if (!isset($baseSeries[$seriesSourceId])) {
            $preserved = $this->preservation(
                $inspection,
                $sourceId,
                "current_v1_contained_work_series",
                CurrentV1SeriesMappingReason::ContainedWorkDeferred->value,
                $bookId,
                "containedWorks",
                $envelope,
                $this->contract->identity()
            );
            $records[] = $preserved;
            $findings[] = $this->finding(
                $sourceId,
                CurrentV1ContainedWorkMappingReason::SeriesIdentityUnavailable,
                [$this->identity($preserved)],
                $this->evidenceHash($preserved),
                MigrationDisposition::PreservedDeferred
            );
            return;
        }
        $prior = $this->preservedPlan(
            $inspection,
            $sourceId,
            "current_v1_contained_work_series",
            CurrentV1SeriesMappingReason::ContainedWorkDeferred->value,
            $bookId,
            "containedWorks",
            $envelope,
            (new CurrentV1ReviewedSeriesContract(
                $this->contract->manifestSha256()
            ))->identity()
        );
        $membership = MigrationSourceRecord::typed(
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkSeriesPlan(
                $workSourceId,
                $seriesSourceId,
                SeriesPosition::known($index),
                $this->contract->identity(),
                $prior
            ),
            [
                CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                    . $workSourceId,
                CatalogSeriesMigrationParticipant::SOURCE_TYPE . ":"
                    . $seriesSourceId,
            ]
        );
        $records[] = $membership;
        $findings[] = $this->finding(
            $sourceId,
            CurrentV1ContainedWorkMappingReason::SeriesMembershipPlanned,
            [$this->identity($membership)]
        );
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function mapIsbn(
        MigrationSourceInspection $inspection,
        string $bookId,
        int $slot,
        string $isbn,
        array &$records,
        array &$findings
    ): void {
        if ($isbn === "") {
            return;
        }
        if (!$this->isbn->parse($isbn)->isValid()) {
            throw $this->unsupported(
                "CURRENT V1 contained ISBN is not safely mappable."
            );
        }
        $sourceId = CurrentV1ContainedWorkSourceIds::isbn($bookId, $slot);
        $record = $this->preservation(
            $inspection,
            $sourceId,
            "current_v1_contained_work_isbn",
            CurrentV1ContainedWorkMappingReason::IsbnDeferred->value,
            $bookId,
            "containedWorks",
            [
                "source_slot" => $sourceId,
                "one_based_slot" => $slot,
                "isbn" => $isbn,
            ],
            $this->contract->identity()
        );
        $records[] = $record;
        $findings[] = $this->finding(
            $sourceId,
            CurrentV1ContainedWorkMappingReason::IsbnDeferred,
            [$this->identity($record)],
            $this->evidenceHash($record),
            MigrationDisposition::PreservedDeferred
        );
    }

    /** @param array<string,mixed> $envelope */
    private function preservation(
        MigrationSourceInspection $inspection,
        string $sourceId,
        string $evidenceType,
        string $reason,
        string $bookId,
        string $sourceField,
        array $envelope,
        string $mappingContract
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            $this->preservedPlan(
                $inspection,
                $sourceId,
                $evidenceType,
                $reason,
                $bookId,
                $sourceField,
                $envelope,
                $mappingContract
            )
        );
    }

    /** @param array<string,mixed> $envelope */
    private function preservedPlan(
        MigrationSourceInspection $inspection,
        string $sourceId,
        string $evidenceType,
        string $reason,
        string $bookId,
        string $sourceField,
        array $envelope,
        string $mappingContract
    ): PreservedSourceEvidencePlan {
        return new PreservedSourceEvidencePlan(
            $sourceId,
            $evidenceType,
            $reason,
            $inspection->adapter()->adapterId(),
            $inspection->adapter()->sourceFamily(),
            $inspection->profile()->sourceVersion(),
            $inspection->package()->manifestDigest(),
            $mappingContract,
            "data/books.json",
            "books",
            $bookId,
            $sourceField,
            DeterministicJson::hash($envelope),
            PreservedSourceEvidencePrivacy::OrdinarySource
        );
    }

    /** @param list<array{source_type:string,source_id:string}> $planned */
    private function finding(
        string $sourceId,
        CurrentV1ContainedWorkMappingReason $reason,
        array $planned = [],
        ?string $evidenceHash = null,
        MigrationDisposition $disposition = MigrationDisposition::Mapped
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            "v1.contained_work",
            $sourceId,
            $disposition,
            $reason->value,
            $planned,
            evidenceHash: $evidenceHash
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }

    private function evidenceHash(MigrationSourceRecord $record): string
    {
        $plan = $record->typedPlan();
        if (!$plan instanceof PreservedSourceEvidencePlan) {
            throw $this->unsupported(
                "CURRENT contained preservation plan is invalid."
            );
        }
        return $plan->evidenceSha256();
    }

    private function unsupported(
        string $message,
        ?\Throwable $previous = null
    ): MigrationRunnerFailure {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::SourceChanged,
            $message,
            $previous
        );
    }
}
