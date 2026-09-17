<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
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
    CatalogSeriesPlan,
    CatalogWorkSeriesMigrationParticipant,
    CatalogWorkSeriesPlan
};
use Biblio\Core\Catalog\SeriesPosition;

/** Implements only the reviewed CURRENT Series semantics from docs/132. */
final readonly class CurrentV1SeriesMapper
{
    public function __construct(
        private CurrentV1ReviewedSeriesContract $contract =
            new CurrentV1ReviewedSeriesContract()
    ) {}

    /**
     * @param array<string, MigrationSourceRecord> $books
     * @param array<string, string> $workRepresentatives
     */
    public function map(
        MigrationSourceInspection $inspection,
        array $books,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals($this->contract->manifestSha256(), $inspection->package()->manifestDigest())) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Series contract does not match the source manifest."
            );
        }

        $records = [];
        $findings = [];
        $memberships = [];

        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $payload = $book->payload();
            if (($payload["id"] ?? null) !== $bookId) {
                continue;
            }

            $this->mapContainedWorks($inspection, $bookId, $payload["containedWorks"] ?? null, $records, $findings);

            $flag = $payload["series"] ?? null;
            $name = $payload["seriesName"] ?? null;
            $number = $payload["seriesNumber"] ?? null;
            if (!is_bool($flag) || !is_string($name) || !is_string($number)) {
                $findings[] = $this->finding(
                    CurrentV1SourceAdapter::BOOK,
                    CurrentV1SeriesSourceIds::membership($bookId),
                    MigrationDisposition::Quarantined,
                    CurrentV1SeriesMappingReason::InvalidStructure
                );
                continue;
            }
            if (!$flag && $name === "" && $number === "") {
                continue;
            }

            if ($name === "") {
                $record = $this->preservation(
                    $inspection,
                    CurrentV1SeriesSourceIds::membership($bookId),
                    "current_v1_series_membership_without_name",
                    CurrentV1SeriesMappingReason::SeriesNameMissing,
                    $bookId,
                    "seriesName",
                    [
                        "source_slot" => CurrentV1SeriesSourceIds::membership($bookId),
                        "series" => $flag,
                        "series_name" => $name,
                        "series_number" => $number,
                    ]
                );
                $records[] = $record;
                $findings[] = $this->finding(
                    CurrentV1SourceAdapter::BOOK,
                    CurrentV1SeriesSourceIds::membership($bookId),
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1SeriesMappingReason::SeriesNameMissing,
                    [$this->identity($record)],
                    $this->preservationEvidenceHash($record)
                );
                continue;
            }

            if (!isset($workRepresentatives[$bookId])) {
                $findings[] = $this->finding(
                    CurrentV1SourceAdapter::BOOK,
                    CurrentV1SeriesSourceIds::membership($bookId),
                    MigrationDisposition::Quarantined,
                    CurrentV1SeriesMappingReason::UnresolvedWork
                );
                continue;
            }

            $seriesSourceId = CurrentV1SeriesSourceIds::series($name);
            $position = SeriesPosition::unknown();
            $positionPreservation = null;
            if ($number !== "") {
                if (preg_match('/^(0|[1-9][0-9]*)$/D', $number) === 1 && !$this->yearLike($number)) {
                    $position = SeriesPosition::known($number);
                } elseif (
                    preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $number) === 1
                    || $this->yearLike($number)
                ) {
                    $positionPreservation = $this->preservation(
                        $inspection,
                        CurrentV1SeriesSourceIds::unsafePosition($bookId),
                        "current_v1_series_position",
                        CurrentV1SeriesMappingReason::UnsafePosition,
                        $bookId,
                        "seriesNumber",
                        [
                            "source_slot" => CurrentV1SeriesSourceIds::unsafePosition($bookId),
                            "series_name" => $name,
                            "series_number" => $number,
                        ]
                    );
                    $records[] = $positionPreservation;
                    $findings[] = $this->finding(
                        CurrentV1SourceAdapter::BOOK,
                        CurrentV1SeriesSourceIds::unsafePosition($bookId),
                        MigrationDisposition::PreservedDeferred,
                        CurrentV1SeriesMappingReason::UnsafePosition,
                        [$this->identity($positionPreservation)],
                        $this->preservationEvidenceHash($positionPreservation)
                    );
                } else {
                    $findings[] = $this->finding(
                        CurrentV1SourceAdapter::BOOK,
                        CurrentV1SeriesSourceIds::membership($bookId),
                        MigrationDisposition::Quarantined,
                        CurrentV1SeriesMappingReason::InvalidStructure
                    );
                    continue;
                }
            }

            $memberships[] = [
                "book_id" => $bookId,
                "representative" => $workRepresentatives[$bookId],
                "series_source_id" => $seriesSourceId,
                "display_name" => $name,
                "position" => $position,
            ];
        }

        $conflicts = $this->conflictingMemberships($memberships);
        $seriesPlans = [];
        foreach ($memberships as $candidate) {
            $membershipSourceId = CurrentV1SeriesSourceIds::membership($candidate["book_id"]);
            if (isset($conflicts[$membershipSourceId])) {
                continue;
            }
            $seriesPlans[$candidate["series_source_id"]] = new CatalogSeriesPlan(
                $candidate["display_name"],
                $this->contract->identity()
            );
        }
        ksort($seriesPlans, SORT_STRING);
        foreach ($seriesPlans as $sourceId => $plan) {
            $record = MigrationSourceRecord::typed(
                CatalogSeriesMigrationParticipant::SOURCE_TYPE,
                $sourceId,
                $plan
            );
            $records[] = $record;
            $findings[] = $this->finding(
                "v1.series",
                $sourceId,
                MigrationDisposition::Mapped,
                CurrentV1SeriesMappingReason::SeriesPlanned,
                [$this->identity($record)]
            );
        }

        foreach ($memberships as $candidate) {
            $sourceId = CurrentV1SeriesSourceIds::membership($candidate["book_id"]);
            if (isset($conflicts[$sourceId])) {
                $findings[] = $this->finding(
                    CurrentV1SourceAdapter::BOOK,
                    $sourceId,
                    MigrationDisposition::Quarantined,
                    CurrentV1SeriesMappingReason::ConvergenceConflict
                );
                continue;
            }
            $workSourceId = CurrentV1CatalogSourceIds::work($candidate["book_id"]);
            $record = MigrationSourceRecord::typed(
                CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
                $sourceId,
                new CatalogWorkSeriesPlan(
                    $workSourceId,
                    $candidate["series_source_id"],
                    $candidate["position"],
                    $this->contract->identity()
                ),
                [
                    CatalogWorkMigrationParticipant::SOURCE_TYPE . ":" . $workSourceId,
                    CatalogSeriesMigrationParticipant::SOURCE_TYPE . ":" . $candidate["series_source_id"],
                ]
            );
            $records[] = $record;
            $findings[] = $this->finding(
                CurrentV1SourceAdapter::BOOK,
                $sourceId,
                MigrationDisposition::Mapped,
                CurrentV1SeriesMappingReason::MembershipPlanned,
                [$this->identity($record)]
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.series_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1SeriesMappingReason::MappingContractApplied->value
        );
        return new MigrationSourceMappingResult($records, $findings);
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function mapContainedWorks(MigrationSourceInspection $inspection, string $bookId, mixed $containedWorks, array &$records, array &$findings): void
    {
        if (!is_array($containedWorks) || !array_is_list($containedWorks)) {
            return;
        }
        foreach ($containedWorks as $offset => $contained) {
            if (!is_array($contained)) {
                continue;
            }
            $name = $contained["series"] ?? null;
            $index = $contained["seriesIndex"] ?? null;
            if (!is_string($name) || !is_string($index) || ($name === "" && $index === "")) {
                continue;
            }
            $slot = $offset + 1;
            $identity = CurrentV1SeriesSourceIds::contained($bookId, $slot);
            $record = $this->preservation(
                $inspection,
                $identity,
                "current_v1_contained_work_series",
                CurrentV1SeriesMappingReason::ContainedWorkDeferred,
                $bookId,
                "containedWorks",
                [
                    "source_slot" => $identity,
                    "one_based_slot" => $slot,
                    "series" => $name,
                    "series_index" => $index,
                ]
            );
            $records[] = $record;
            $findings[] = $this->finding(
                CurrentV1SourceAdapter::BOOK,
                $identity,
                MigrationDisposition::PreservedDeferred,
                CurrentV1SeriesMappingReason::ContainedWorkDeferred,
                [$this->identity($record)],
                $this->preservationEvidenceHash($record)
            );
        }
    }

    /**
     * @param list<array{book_id:string,representative:string,series_source_id:string,display_name:string,position:SeriesPosition}> $memberships
     * @return array<string, true>
     */
    private function conflictingMemberships(array $memberships): array
    {
        $groups = [];
        foreach ($memberships as $candidate) {
            $groups[$candidate["representative"]][] = $candidate;
        }
        $conflicts = [];
        foreach ($groups as $group) {
            $series = [];
            $knownPositions = [];
            foreach ($group as $candidate) {
                $series[$candidate["series_source_id"]] = true;
                if ($candidate["position"]->isKnown()) {
                    $knownPositions[$candidate["position"]->value()] = true;
                }
            }
            if (count($series) <= 1 && count($knownPositions) <= 1) {
                continue;
            }
            foreach ($group as $candidate) {
                $conflicts[CurrentV1SeriesSourceIds::membership($candidate["book_id"])] = true;
            }
        }
        return $conflicts;
    }

    private function yearLike(string $value): bool
    {
        return preg_match('/^(?:1[0-9]{3}|2[0-9]{3})$/D', $value) === 1;
    }

    /** @param array<string, mixed> $envelope */
    private function preservation(MigrationSourceInspection $inspection, string $sourceIdentity, string $evidenceType, CurrentV1SeriesMappingReason $reason, string $bookId, string $sourceField, array $envelope): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
            $sourceIdentity,
            new PreservedSourceEvidencePlan(
                $sourceIdentity,
                $evidenceType,
                $reason->value,
                $inspection->adapter()->adapterId(),
                $inspection->adapter()->sourceFamily(),
                $inspection->profile()->sourceVersion(),
                $inspection->package()->manifestDigest(),
                $this->contract->identity(),
                "data/books.json",
                "books",
                $bookId,
                $sourceField,
                DeterministicJson::hash($envelope),
                PreservedSourceEvidencePrivacy::OrdinarySource
            )
        );
    }

    /** @param list<array{source_type:string,source_id:string}> $plannedIdentities */
    private function finding(string $sourceType, string $sourceId, MigrationDisposition $disposition, CurrentV1SeriesMappingReason $reason, array $plannedIdentities = [], ?string $evidenceHash = null): MigrationSourceMappingFinding
    {
        return new MigrationSourceMappingFinding(
            $sourceType,
            $sourceId,
            $disposition,
            $reason->value,
            $plannedIdentities,
            evidenceHash: $evidenceHash
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return ["source_type" => $record->sourceType(), "source_id" => $record->sourceId()];
    }

    private function preservationEvidenceHash(MigrationSourceRecord $record): string
    {
        $plan = $record->typedPlan();
        if (!$plan instanceof PreservedSourceEvidencePlan) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "CURRENT Series preservation plan is invalid."
            );
        }
        return $plan->evidenceSha256();
    }
}
