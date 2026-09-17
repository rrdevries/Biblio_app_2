<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingRoundPlan,
    ReadingTruthMigrationParticipant,
    ReadingTruthPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\{
    PersonalReadingTruthState,
    ReadingDate,
    ReadingPeriod,
    ReadingRoundOutcome
};

/** Implements only the reviewed CURRENT reading semantics from docs/124. */
final readonly class CurrentV1ReadingMapper
{
    public function __construct(
        private CurrentV1ReviewedReadingContract $contract =
            new CurrentV1ReviewedReadingContract()
    ) {
    }

    /**
     * @param array<string, MigrationSourceRecord> $books
     * @param array<string, MigrationSourceRecord> $rounds
     * @param array<string, string> $workRepresentatives book source ID => representative Book source ID
     */
    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target,
        array $books,
        array $rounds,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Reading contract does not match the source manifest."
            );
        }
        $records = [];
        $findings = [];
        $targetUserId = new UserId($target->userId());
        $roundsByBook = $this->roundsByBook($rounds);
        $consumedRounds = [];
        $groups = [];

        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $payload = $book->payload();
            $embeddedRounds = $payload["readingRounds"] ?? [];
            $registration = $payload["readRegistration"] ?? null;
            $status = $payload["readStatus"] ?? null;
            $marker = $payload["readMarker"] ?? null;

            if (!is_array($embeddedRounds) || !array_is_list($embeddedRounds)) {
                throw new ValidationException("CURRENT V1 readingRounds must be a list.");
            }
            if (count($embeddedRounds) > 1) {
                throw new ValidationException(
                    "CURRENT V1 Book has more than one reviewed stable ReadingRound."
                );
            }
            if ($embeddedRounds !== [] && $registration !== null) {
                throw new ValidationException(
                    "CURRENT V1 Book overlaps a ReadingRound and read registration."
                );
            }
            $this->assertStatusMarker($status, $marker);

            [$paused, $derivedHistory] = $this->historyCounts(
                $payload["readHistory"] ?? []
            );
            if ($paused > 0) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ReadingMappingReason::HistoricalPausedAuditEvidence,
                    occurrenceCount: $paused
                );
            }
            if ($derivedHistory > 0) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Transformed,
                    CurrentV1ReadingMappingReason::DerivedReadingHistoryEvidence,
                    occurrenceCount: $derivedHistory
                );
            }

            $representative = $workRepresentatives[$bookId] ?? null;
            $candidate = null;
            $hasConcreteRound = false;
            if ($embeddedRounds !== []) {
                $raw = $embeddedRounds[0];
                if (!is_array($raw) || !is_string($raw["id"] ?? null)) {
                    throw new ValidationException(
                        "CURRENT V1 ReadingRound identity is malformed."
                    );
                }
                $round = $roundsByBook[$bookId][$raw["id"]] ?? null;
                if (
                    !$round instanceof MigrationSourceRecord
                    || ($round->payload()["record"] ?? null) !== $raw
                ) {
                    throw new ValidationException(
                        "CURRENT V1 stable ReadingRound record does not match its Book."
                    );
                }
                $consumedRounds[$this->recordKey($round)] = true;
                $shape = $this->roundShape($raw);
                if ($representative === null) {
                    $findings[] = $this->finding(
                        $round,
                        MigrationDisposition::Quarantined,
                        CurrentV1ReadingMappingReason::UnresolvedWorkIdentity
                    );
                } elseif ($shape["outcome"] === null) {
                    $findings[] = $this->finding(
                        $round,
                        MigrationDisposition::PreservedDeferred,
                        CurrentV1ReadingMappingReason::ActiveRoundMissingConcreteSource
                    );
                } else {
                    $record = MigrationSourceRecord::typed(
                        ReadingRoundMigrationParticipant::SOURCE_TYPE,
                        $round->sourceId(),
                        new ReadingRoundPlan(
                            $targetUserId,
                            CurrentV1CatalogSourceIds::work($bookId),
                            $shape["outcome"],
                            ReadingPeriod::ended($shape["start"], $shape["end"])
                        ),
                        [$this->workReference($bookId)]
                    );
                    $records[] = $record;
                    $findings[] = $this->finding(
                        $round,
                        MigrationDisposition::Mapped,
                        CurrentV1ReadingMappingReason::StableReadingRoundPlanned,
                        [$this->identity($record)]
                    );
                    $hasConcreteRound = true;
                }
            } elseif ($registration !== null) {
                if (!is_array($registration)) {
                    throw new ValidationException(
                        "CURRENT V1 read registration must be an object."
                    );
                }
                $mode = $registration["mode"] ?? null;
                if ($mode === "partial_finish") {
                    $finished = $this->date($registration["partialDate"] ?? null);
                    $sourceId = CurrentV1ReadingSourceIds::registration($bookId);
                    if ($representative === null) {
                        $findings[] = $this->bookSlotFinding(
                            $sourceId,
                            MigrationDisposition::Quarantined,
                            CurrentV1ReadingMappingReason::UnresolvedWorkIdentity
                        );
                    } else {
                        $record = MigrationSourceRecord::typed(
                            ReadingRoundMigrationParticipant::SOURCE_TYPE,
                            $sourceId,
                            new ReadingRoundPlan(
                                $targetUserId,
                                CurrentV1CatalogSourceIds::work($bookId),
                                ReadingRoundOutcome::Completed,
                                ReadingPeriod::ended(null, $finished)
                            ),
                            [$this->workReference($bookId)]
                        );
                        $records[] = $record;
                        $findings[] = $this->finding(
                            $book,
                            MigrationDisposition::Mapped,
                            CurrentV1ReadingMappingReason::RegistrationReadingRoundPlanned,
                            [$this->identity($record)]
                        );
                        $hasConcreteRound = true;
                    }
                } elseif ($mode === "unknown_date") {
                    if (array_key_exists("partialDate", $registration)) {
                        throw new ValidationException(
                            "Unknown-date registration cannot carry a partial date."
                        );
                    }
                    $candidate = $this->candidate(
                        $book,
                        CurrentV1ReadingSourceIds::registration($bookId),
                        PersonalReadingTruthState::ReadKnownDateUnknown,
                        "registration"
                    );
                } else {
                    throw new ValidationException(
                        "CURRENT V1 read registration mode is unsupported."
                    );
                }
            } elseif ($status === "unread") {
                $candidate = $this->candidate(
                    $book,
                    CurrentV1ReadingSourceIds::status($bookId),
                    PersonalReadingTruthState::ExplicitNotRead,
                    "status"
                );
            } elseif ($status === "unknown") {
                $candidate = $this->candidate(
                    $book,
                    CurrentV1ReadingSourceIds::status($bookId),
                    PersonalReadingTruthState::Unknown,
                    "status"
                );
            } elseif (!in_array($status, ["finished", "reading", "stopped"], true)) {
                throw new ValidationException("CURRENT V1 read status is unsupported.");
            }

            if (in_array($status, ["finished", "reading", "stopped"], true)) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Transformed,
                    CurrentV1ReadingMappingReason::DerivedBookReadingSummary
                );
            }

            if ($representative === null) {
                if ($candidate !== null) {
                    $findings[] = $this->bookSlotFinding(
                        $candidate["source_id"],
                        MigrationDisposition::Quarantined,
                        CurrentV1ReadingMappingReason::UnresolvedWorkIdentity
                    );
                }
                continue;
            }
            $groups[$representative][] = [
                "book" => $book,
                "candidate" => $candidate,
                "has_concrete_round" => $hasConcreteRound,
            ];
        }

        foreach ($rounds as $round) {
            if (!isset($consumedRounds[$this->recordKey($round)])) {
                throw new ValidationException(
                    "CURRENT V1 ReadingRound is not referenced by its parent Book."
                );
            }
        }

        foreach ($groups as $representative => $members) {
            $this->mapTruthGroup(
                $targetUserId,
                (string) $representative,
                $members,
                $records,
                $findings
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.reading_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1ReadingMappingReason::MappingContractApplied->value
        );

        return new MigrationSourceMappingResult($records, $findings);
    }

    /**
     * @param array<string, MigrationSourceRecord> $rounds
     * @return array<string, array<string, MigrationSourceRecord>>
     */
    private function roundsByBook(array $rounds): array
    {
        $indexed = [];
        foreach ($rounds as $round) {
            $bookId = $round->payload()["book_id"] ?? null;
            if (!is_string($bookId) || $bookId === "") {
                throw new ValidationException("CURRENT V1 ReadingRound has no parent Book.");
            }
            $indexed[$bookId][$round->sourceId()] = $round;
        }
        return $indexed;
    }

    /**
     * @param array<string,mixed> $round
     * @return array{outcome:?ReadingRoundOutcome,start:ReadingDate,end:?ReadingDate}
     */
    private function roundShape(array $round): array
    {
        $start = $this->date($round["startedAtPartial"] ?? null);
        $finish = $round["finishedAtPartial"] ?? null;
        $stop = $round["stoppedAtPartial"] ?? null;
        if ($finish !== null && $stop !== null) {
            throw new ValidationException(
                "CURRENT V1 ReadingRound cannot be both finished and stopped."
            );
        }
        if ($finish !== null) {
            return [
                "outcome" => ReadingRoundOutcome::Completed,
                "start" => $start,
                "end" => $this->date($finish),
            ];
        }
        if ($stop !== null) {
            return [
                "outcome" => ReadingRoundOutcome::Stopped,
                "start" => $start,
                "end" => $this->date($stop),
            ];
        }
        return ["outcome" => null, "start" => $start, "end" => null];
    }

    private function date(mixed $value): ReadingDate
    {
        if (!is_array($value)) {
            throw new ValidationException("CURRENT V1 partial reading date is missing.");
        }
        $precision = $value["precision"] ?? null;
        $raw = $value["value"] ?? null;
        if (!is_string($precision) || !is_string($raw)) {
            throw new ValidationException("CURRENT V1 partial reading date is malformed.");
        }
        if ($precision === "year" && preg_match('/^(\d{4})$/D', $raw, $match) === 1) {
            return ReadingDate::year((int) $match[1]);
        }
        if ($precision === "month" && preg_match('/^(\d{4})-(\d{2})$/D', $raw, $match) === 1) {
            return ReadingDate::month((int) $match[1], (int) $match[2]);
        }
        if ($precision === "day" && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $match) === 1) {
            return ReadingDate::exact(
                (int) $match[1],
                (int) $match[2],
                (int) $match[3]
            );
        }
        throw new ValidationException(
            "CURRENT V1 partial reading date precision or value is unsupported."
        );
    }

    private function assertStatusMarker(mixed $status, mixed $marker): void
    {
        $expected = match ($status) {
            "finished", "reading", "stopped" => "yes",
            "unread" => "no",
            "unknown" => "unknown",
            default => null,
        };
        if ($expected === null || $marker !== $expected) {
            throw new ValidationException(
                "CURRENT V1 read status and marker contradict each other."
            );
        }
    }

    /** @return array{int,int} paused and derived occurrence counts */
    private function historyCounts(mixed $history): array
    {
        if (!is_array($history) || !array_is_list($history)) {
            throw new ValidationException("CURRENT V1 read history must be a list.");
        }
        $paused = 0;
        $derived = 0;
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                throw new ValidationException(
                    "CURRENT V1 read history entry must be an object."
                );
            }
            if (($entry["status"] ?? null) === "paused") {
                ++$paused;
            } else {
                ++$derived;
            }
        }
        return [$paused, $derived];
    }

    /** @return array{book:MigrationSourceRecord,source_id:string,state:PersonalReadingTruthState,kind:string} */
    private function candidate(
        MigrationSourceRecord $book,
        string $sourceId,
        PersonalReadingTruthState $state,
        string $kind
    ): array {
        return [
            "book" => $book,
            "source_id" => $sourceId,
            "state" => $state,
            "kind" => $kind,
        ];
    }

    /**
     * @param list<array{
     *   book:MigrationSourceRecord,
     *   candidate:array{book:MigrationSourceRecord,source_id:string,state:PersonalReadingTruthState,kind:string}|null,
     *   has_concrete_round:bool
     * }> $members
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function mapTruthGroup(
        UserId $targetUserId,
        string $representative,
        array $members,
        array &$records,
        array &$findings
    ): void {
        $candidates = [];
        $hasConcreteRound = false;
        foreach ($members as $member) {
            $hasConcreteRound = $hasConcreteRound || $member["has_concrete_round"];
            if (is_array($member["candidate"])) {
                $candidates[] = $member["candidate"];
            }
        }
        if ($candidates === []) {
            return;
        }

        $readKnown = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool =>
                $candidate["state"] === PersonalReadingTruthState::ReadKnownDateUnknown
        ));
        if ($hasConcreteRound && $readKnown !== []) {
            foreach ($readKnown as $candidate) {
                $findings[] = $this->candidateFinding(
                    $candidate,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ReadingMappingReason::AmbiguousDuplicateOrReread
                );
            }
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool =>
                    $candidate["state"] !== PersonalReadingTruthState::ReadKnownDateUnknown
            ));
        }
        if ($candidates === []) {
            return;
        }

        $readKnown = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool =>
                $candidate["state"] === PersonalReadingTruthState::ReadKnownDateUnknown
        ));
        $notRead = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool =>
                $candidate["state"] === PersonalReadingTruthState::ExplicitNotRead
        ));
        if ($readKnown !== [] && $notRead !== []) {
            foreach ($notRead as $candidate) {
                $findings[] = $this->candidateFinding(
                    $candidate,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ReadingMappingReason::ConflictingBookReadStatus
                );
            }
            $candidates = $readKnown;
        }

        $states = [];
        foreach ($candidates as $candidate) {
            $states[$candidate["state"]->value] = true;
        }
        if (count($states) !== 1) {
            foreach ($candidates as $candidate) {
                $findings[] = $this->candidateFinding(
                    $candidate,
                    MigrationDisposition::Quarantined,
                    CurrentV1ReadingMappingReason::ReadingTruthConflict
                );
            }
            return;
        }

        usort($candidates, static fn (array $left, array $right): int =>
            [
                $left["book"]->sourceId() === $representative ? 0 : 1,
                $left["source_id"],
            ] <=> [
                $right["book"]->sourceId() === $representative ? 0 : 1,
                $right["source_id"],
            ]);
        $selected = array_shift($candidates);
        foreach ($candidates as $duplicate) {
            $findings[] = $this->candidateFinding(
                $duplicate,
                MigrationDisposition::Transformed,
                CurrentV1ReadingMappingReason::DuplicateReadingTruthEvidence
            );
        }

        $bookId = $selected["book"]->sourceId();
        $record = MigrationSourceRecord::typed(
            ReadingTruthMigrationParticipant::SOURCE_TYPE,
            $selected["source_id"],
            new ReadingTruthPlan(
                $targetUserId,
                CurrentV1CatalogSourceIds::work($bookId),
                $selected["state"]
            ),
            [$this->workReference($bookId)]
        );
        $records[] = $record;
        $findings[] = $this->candidateFinding(
            $selected,
            MigrationDisposition::Mapped,
            CurrentV1ReadingMappingReason::PersonalReadingTruthPlanned,
            [$this->identity($record)]
        );
    }

    /**
     * @param array{book:MigrationSourceRecord,source_id:string,state:PersonalReadingTruthState,kind:string} $candidate
     * @param list<array{source_type:string,source_id:string}> $plannedIdentities
     */
    private function candidateFinding(
        array $candidate,
        MigrationDisposition $disposition,
        CurrentV1ReadingMappingReason $reason,
        array $plannedIdentities = []
    ): MigrationSourceMappingFinding {
        return $this->bookSlotFinding(
            $candidate["source_id"],
            $disposition,
            $reason,
            $plannedIdentities
        );
    }

    /** @param list<array{source_type:string,source_id:string}> $plannedIdentities */
    private function bookSlotFinding(
        string $sourceId,
        MigrationDisposition $disposition,
        CurrentV1ReadingMappingReason $reason,
        array $plannedIdentities = []
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            CurrentV1SourceAdapter::BOOK,
            $sourceId,
            $disposition,
            $reason->value,
            $plannedIdentities
        );
    }

    /** @param list<array{source_type:string,source_id:string}> $plannedIdentities */
    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1ReadingMappingReason $reason,
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

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }

    private function workReference(string $bookId): string
    {
        return CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
            . CurrentV1CatalogSourceIds::work($bookId);
    }

    private function recordKey(MigrationSourceRecord $record): string
    {
        return $record->sourceType() . "\0" . $record->sourceId();
    }
}
