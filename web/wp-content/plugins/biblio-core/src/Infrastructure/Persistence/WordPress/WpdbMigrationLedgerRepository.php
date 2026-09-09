<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationLedgerRepository;
use Biblio\Core\Application\Migration\MigrationMode;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationReconciliation;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationRunStatus;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\MigrationTraceEntry;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final readonly class WpdbMigrationLedgerRepository implements MigrationLedgerRepository
{
    private const DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function beginOrResume(MigrationRun $run): MigrationRun
    {
        $existing = $this->findByIdentity($run);
        if ($existing === null) {
            if ($this->database->insert(
                $this->tables->migrationRuns(),
                $this->runRow($run),
                array_fill(0, 14, "%s")
            ) !== 1) {
                $existing = $this->findByIdentity($run);
                if ($existing === null) {
                    throw WpdbErrorTranslator::writeFailure(
                        "Could not persist migration run.",
                        $this->database->last_error
                    );
                }
            } else {
                $existing = $run;
            }
        }

        if ($existing->status() === MigrationRunStatus::Completed) {
            return $existing;
        }
        $this->acquireTargetLock($existing);
        return $existing;
    }

    public function findRun(string $runId): ?MigrationRun
    {
        $table = $this->tables->migrationRuns();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM " . $table . " WHERE run_id=%s",
            $runId
        ), ARRAY_A);

        return $row === null ? null : $this->runFromRow($row);
    }

    public function saveRun(MigrationRun $run): void
    {
        $result = $this->database->update(
            $this->tables->migrationRuns(),
            [
                "run_status" => $run->status()->value,
                "summary_status" => $run->summaryStatus(),
                "started_at" => $run->startedAt() === null ? null : $this->date($run->startedAt()),
                "finished_at" => $run->finishedAt() === null ? null : $this->date($run->finishedAt()),
            ],
            ["run_id" => $run->id()],
            ["%s", "%s", "%s", "%s"],
            ["%s"]
        );
        if ($result !== 1) {
            throw new PersistenceException(
                "Could not update migration run.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }
    }

    public function releaseRunLock(string $runId): void
    {
        if ($this->database->delete(
            $this->tables->migrationRunLocks(),
            ["run_id" => $runId],
            ["%s"]
        ) !== 1) {
            throw new PersistenceException(
                "Could not release migration run lock.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }
    }

    public function addOrFindObservation(SourceObservation $observation): SourceObservation
    {
        $existing = $this->findObservationBySource($observation);
        if ($existing !== null) {
            $this->assertSameObservation($existing, $observation);
            return $existing;
        }

        $inserted = $this->withoutDatabaseErrorOutput(fn (): int|false => $this->database->insert(
            $this->tables->migrationSourceObservations(),
            $this->observationRow($observation),
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s", "%s"]
        ));
        if ($inserted !== 1) {
            $existing = $this->findObservationBySource($observation);
            if ($existing === null) {
                throw WpdbErrorTranslator::writeFailure(
                    "Could not persist migration source observation.",
                    $this->database->last_error
                );
            }
            $this->assertSameObservation($existing, $observation);
            return $existing;
        }

        return $observation;
    }

    public function lockObservation(string $runId, string $observationId): SourceObservation
    {
        $table = $this->tables->migrationSourceObservations();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM " . $table . " WHERE run_id=%s AND observation_id=%s FOR UPDATE",
            $runId,
            $observationId
        ), ARRAY_A);
        if ($row === null) {
            throw new ValidationException("Migration source observation is unavailable.");
        }

        return $this->observationFromRow($row);
    }

    public function commitOutcome(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void {
        foreach ($outcome->mappings() as $mapping) {
            $this->insertMapping($observation, $mapping, $at);
        }
        if ($outcome->disposition() === MigrationDisposition::Quarantined) {
            $this->insertQuarantine($observation, $outcome, $at);
        }
        if ($outcome->disposition() === MigrationDisposition::PreservedDeferred) {
            $this->insertPreservation($observation, $outcome, $at);
        }

        if ($this->database->update(
            $this->tables->migrationSourceObservations(),
            [
                "processing_status" => $outcome->processingStatus(),
                "disposition" => $outcome->disposition()->value,
                "reason_code" => $outcome->reasonCode() ?? $observation->reasonCode(),
                "retryable" => $outcome->retryable() ? 1 : 0,
                "updated_at" => $this->date($at),
            ],
            ["observation_id" => $observation->id(), "run_id" => $observation->runId()],
            ["%s", "%s", "%s", "%d", "%s"],
            ["%s", "%s"]
        ) !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not commit migration source outcome.",
                $this->database->last_error
            );
        }
    }

    public function reconciliation(string $runId): MigrationReconciliation
    {
        $observations = $this->tables->migrationSourceObservations();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT disposition,COUNT(*) AS total FROM " . $observations
                . " WHERE run_id=%s GROUP BY disposition",
            $runId
        ), ARRAY_A);
        $counts = array_fill_keys(array_map(
            static fn (MigrationDisposition $case): string => $case->value,
            MigrationDisposition::cases()
        ), 0);
        $uncommitted = 0;
        foreach ($rows as $row) {
            if ($row["disposition"] === null) {
                $uncommitted += (int) $row["total"];
            } else {
                $counts[(string) $row["disposition"]] = (int) $row["total"];
            }
        }

        $mappings = $this->tables->migrationTargetMappings();
        $targets = $this->database->get_row($this->database->prepare(
            "SELECT COUNT(*) AS edges,"
                . "SUM(mapping_disposition='created') AS created_count,"
                . "SUM(mapping_disposition='reused') AS reused_count "
                . "FROM " . $mappings . " WHERE run_id=%s",
            $runId
        ), ARRAY_A);

        return new MigrationReconciliation(
            $runId,
            array_sum($counts) + $uncommitted,
            $counts,
            (int) ($targets["created_count"] ?? 0),
            (int) ($targets["reused_count"] ?? 0),
            (int) ($targets["edges"] ?? 0),
            $uncommitted
        );
    }

    public function priorTargets(
        MigrationRun $run,
        SourceObservation $observation
    ): array {
        if ($observation->runId() !== $run->id()) {
            throw new ValidationException("Source observation belongs to another run.");
        }
        $rows = $this->traceRows(
            "r.target_user_id=%s AND r.target_library_id=%s AND o.source_family=%s "
                . "AND o.source_type=%s AND o.source_id=%s AND o.payload_hash=%s AND o.run_id<>%s",
            [
                $run->targetUserId()->value(),
                $run->targetLibraryId()->value(),
                $run->sourceFamily(),
                $observation->sourceType(),
                $observation->sourceId(),
                $observation->payloadHash(),
                $run->id(),
            ]
        );

        $targets = [];
        foreach ($rows as $row) {
            $key = (string) $row["target_entity_type"] . "\0" . (string) $row["target_entity_id"];
            $targets[$key] = new MigrationTargetMapping(
                (string) $row["target_entity_type"],
                (string) $row["target_entity_id"],
                MappingDisposition::Reused,
                $row["reason_code"] === null || $row["reason_code"] === ""
                    ? null
                    : (string) $row["reason_code"]
            );
        }

        return array_values($targets);
    }

    public function sourceTargets(MigrationRun $run, string $sourceType, string $sourceId): array
    {
        return $this->traces($this->traceRows(
            "r.target_user_id=%s AND r.target_library_id=%s AND o.source_family=%s "
                . "AND o.source_type=%s AND o.source_id=%s",
            [
                $run->targetUserId()->value(),
                $run->targetLibraryId()->value(),
                $run->sourceFamily(),
                $sourceType,
                $sourceId,
            ]
        ));
    }

    public function targetSources(MigrationRun $run, string $targetType, string $targetId): array
    {
        return $this->traces($this->traceRows(
            "r.target_user_id=%s AND r.target_library_id=%s "
                . "AND m.target_entity_type=%s AND m.target_entity_id=%s",
            [
                $run->targetUserId()->value(),
                $run->targetLibraryId()->value(),
                $targetType,
                $targetId,
            ]
        ));
    }

    private function acquireTargetLock(MigrationRun $run): void
    {
        $table = $this->tables->migrationRunLocks();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT run_id FROM " . $table
                . " WHERE target_user_id=%s AND target_library_id=%s FOR UPDATE",
            $run->targetUserId()->value(),
            $run->targetLibraryId()->value()
        ), ARRAY_A);
        if ($row !== null) {
            if ((string) $row["run_id"] !== $run->id()) {
                throw new ConflictException(
                    "Another migration run owns the target context.",
                    FailureReason::MigrationRunConflict
                );
            }
            return;
        }

        if ($this->database->insert($table, [
            "target_user_id" => $run->targetUserId()->value(),
            "target_library_id" => $run->targetLibraryId()->value(),
            "run_id" => $run->id(),
            "acquired_at" => $this->date($run->createdAt()),
        ], ["%s", "%s", "%s", "%s"]) !== 1) {
            throw new ConflictException(
                "Migration target context could not be locked.",
                FailureReason::MigrationRunConflict
            );
        }
    }

    private function insertMapping(
        SourceObservation $observation,
        MigrationTargetMapping $mapping,
        DateTimeImmutable $at
    ): void {
        $id = hash("sha256", implode("\0", [
            $observation->runId(),
            $observation->id(),
            $mapping->targetType(),
            $mapping->targetId(),
        ]));
        $table = $this->tables->migrationTargetMappings();
        $result = $this->database->query($this->database->prepare(
            "INSERT INTO " . $table
                . " (mapping_id,run_id,observation_id,target_entity_type,target_entity_id,"
                . "mapping_disposition,mapping_status,reason_code,created_at) "
                . "VALUES (%s,%s,%s,%s,%s,%s,'committed',%s,%s) "
                . "ON DUPLICATE KEY UPDATE mapping_id=VALUES(mapping_id)",
            $id,
            $observation->runId(),
            $observation->id(),
            $mapping->targetType(),
            $mapping->targetId(),
            $mapping->disposition()->value,
            $mapping->reasonCode(),
            $this->date($at)
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist migration target mapping.",
                $this->database->last_error
            );
        }
    }

    private function insertQuarantine(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void {
        $id = hash("sha256", "quarantine\0" . $observation->id());
        $inserted = $this->withoutDatabaseErrorOutput(fn (): int|false => $this->database->insert($this->tables->migrationQuarantine(), [
            "quarantine_id" => $id,
            "run_id" => $observation->runId(),
            "observation_id" => $observation->id(),
            "reason_code" => $outcome->quarantineReason()?->value,
            "explanation" => $outcome->explanation(),
            "resolution_status" => "open",
            "evidence_json" => $outcome->evidenceJson(),
            "evidence_reference" => $outcome->evidenceReference(),
            "created_at" => $this->date($at),
            "resolved_at" => null,
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]));
        if ($inserted !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist migration quarantine.",
                $this->database->last_error
            );
        }
    }

    private function insertPreservation(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void {
        $id = hash("sha256", "preservation\0" . $observation->id());
        $inserted = $this->withoutDatabaseErrorOutput(fn (): int|false => $this->database->insert($this->tables->migrationPreservations(), [
            "preservation_id" => $id,
            "run_id" => $observation->runId(),
            "observation_id" => $observation->id(),
            "reason_code" => $outcome->preservationReason(),
            "processing_status" => "awaiting_future_processing",
            "evidence_json" => $outcome->evidenceJson(),
            "evidence_reference" => $outcome->evidenceReference(),
            "created_at" => $this->date($at),
            "processed_at" => null,
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]));
        if ($inserted !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist migration preservation.",
                $this->database->last_error
            );
        }
    }

    private function findByIdentity(MigrationRun $run): ?MigrationRun
    {
        $table = $this->tables->migrationRuns();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM " . $table
                . " WHERE source_family=%s AND source_snapshot=%s AND source_fingerprint=%s"
                . " AND migrator_version=%s AND target_user_id=%s AND target_library_id=%s"
                . " AND run_mode=%s",
            $run->sourceFamily(),
            $run->sourceSnapshot(),
            $run->sourceFingerprint(),
            $run->migratorVersion(),
            $run->targetUserId()->value(),
            $run->targetLibraryId()->value(),
            $run->mode()->value
        ), ARRAY_A);

        return $row === null ? null : $this->runFromRow($row);
    }

    private function findObservationBySource(SourceObservation $observation): ?SourceObservation
    {
        $table = $this->tables->migrationSourceObservations();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM " . $table
                . " WHERE run_id=%s AND source_family=%s AND source_type=%s AND source_id=%s",
            $observation->runId(),
            $observation->sourceFamily(),
            $observation->sourceType(),
            $observation->sourceId()
        ), ARRAY_A);

        return $row === null ? null : $this->observationFromRow($row);
    }

    private function assertSameObservation(SourceObservation $stored, SourceObservation $candidate): void
    {
        if (
            $stored->id() !== $candidate->id()
            || $stored->sourceSnapshot() !== $candidate->sourceSnapshot()
            || $stored->payloadHash() !== $candidate->payloadHash()
        ) {
            throw new ConflictException(
                "The source identity was already observed with different content in this run.",
                FailureReason::MigrationRecordConflict
            );
        }
    }

    /** @return array<string, string|null> */
    private function runRow(MigrationRun $run): array
    {
        return [
            "run_id" => $run->id(),
            "source_family" => $run->sourceFamily(),
            "source_snapshot" => $run->sourceSnapshot(),
            "source_fingerprint" => $run->sourceFingerprint(),
            "source_version" => $run->sourceVersion(),
            "migrator_version" => $run->migratorVersion(),
            "target_user_id" => $run->targetUserId()->value(),
            "target_library_id" => $run->targetLibraryId()->value(),
            "run_mode" => $run->mode()->value,
            "run_status" => $run->status()->value,
            "summary_status" => $run->summaryStatus(),
            "created_at" => $this->date($run->createdAt()),
            "started_at" => $run->startedAt() === null ? null : $this->date($run->startedAt()),
            "finished_at" => $run->finishedAt() === null ? null : $this->date($run->finishedAt()),
        ];
    }

    /** @return array<string, string|int|null> */
    private function observationRow(SourceObservation $observation): array
    {
        return [
            "observation_id" => $observation->id(),
            "run_id" => $observation->runId(),
            "source_family" => $observation->sourceFamily(),
            "source_type" => $observation->sourceType(),
            "source_id" => $observation->sourceId(),
            "source_snapshot" => $observation->sourceSnapshot(),
            "payload_hash" => $observation->payloadHash(),
            "payload_json" => $observation->payloadJson(),
            "payload_reference" => $observation->payloadReference(),
            "processing_status" => $observation->processingStatus(),
            "disposition" => $observation->disposition()?->value,
            "reason_code" => $observation->reasonCode(),
            "retryable" => $observation->retryable() ? 1 : 0,
            "created_at" => $this->date($observation->createdAt()),
            "updated_at" => $this->date($observation->updatedAt()),
        ];
    }

    /** @param array<string, mixed> $row */
    private function runFromRow(array $row): MigrationRun
    {
        return new MigrationRun(
            (string) $row["run_id"],
            (string) $row["source_family"],
            (string) $row["source_snapshot"],
            (string) $row["source_fingerprint"],
            $row["source_version"] === null ? null : (string) $row["source_version"],
            (string) $row["migrator_version"],
            new UserId((string) $row["target_user_id"]),
            new LibraryId((string) $row["target_library_id"]),
            MigrationMode::from((string) $row["run_mode"]),
            MigrationRunStatus::from((string) $row["run_status"]),
            (string) $row["summary_status"],
            $this->parseDate((string) $row["created_at"]),
            $row["started_at"] === null ? null : $this->parseDate((string) $row["started_at"]),
            $row["finished_at"] === null ? null : $this->parseDate((string) $row["finished_at"])
        );
    }

    /** @param array<string, mixed> $row */
    private function observationFromRow(array $row): SourceObservation
    {
        return new SourceObservation(
            (string) $row["observation_id"],
            (string) $row["run_id"],
            (string) $row["source_family"],
            (string) $row["source_type"],
            (string) $row["source_id"],
            (string) $row["source_snapshot"],
            (string) $row["payload_hash"],
            $row["payload_json"] === null ? null : (string) $row["payload_json"],
            $row["payload_reference"] === null ? null : (string) $row["payload_reference"],
            (string) $row["processing_status"],
            $row["disposition"] === null ? null : MigrationDisposition::from((string) $row["disposition"]),
            $row["reason_code"] === null ? null : (string) $row["reason_code"],
            (int) $row["retryable"] === 1,
            $this->parseDate((string) $row["created_at"]),
            $this->parseDate((string) $row["updated_at"])
        );
    }

    /**
     * @param list<string> $parameters
     * @return list<array<string, mixed>>
     */
    private function traceRows(string $where, array $parameters): array
    {
        $observations = $this->tables->migrationSourceObservations();
        $mappings = $this->tables->migrationTargetMappings();
        $runs = $this->tables->migrationRuns();
        $query = "SELECT o.run_id,o.source_family,o.source_type,o.source_id,"
            . "o.source_snapshot,o.payload_hash,m.target_entity_type,m.target_entity_id,"
            . "m.mapping_disposition,m.reason_code "
            . "FROM " . $observations . " o INNER JOIN " . $mappings
            . " m ON m.observation_id=o.observation_id AND m.run_id=o.run_id "
            . "INNER JOIN " . $runs . " r ON r.run_id=o.run_id "
            . "WHERE " . $where
            . " ORDER BY o.created_at,o.observation_id,m.target_entity_type,m.target_entity_id";

        return $this->database->get_results(
            $this->database->prepare($query, ...$parameters),
            ARRAY_A
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<MigrationTraceEntry>
     */
    private function traces(array $rows): array
    {
        return array_map(
            static fn (array $row): MigrationTraceEntry => new MigrationTraceEntry(
                (string) $row["run_id"],
                (string) $row["source_family"],
                (string) $row["source_type"],
                (string) $row["source_id"],
                (string) $row["source_snapshot"],
                (string) $row["payload_hash"],
                (string) $row["target_entity_type"],
                (string) $row["target_entity_id"],
                MappingDisposition::from((string) $row["mapping_disposition"])
            ),
            $rows
        );
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        if ($date === false) {
            throw new PersistenceException(
                "Stored migration timestamp is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $date;
    }

    /**
     * WordPress may otherwise print or log the full SQL query, including
     * private migration evidence, when a write fails.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withoutDatabaseErrorOutput(callable $operation): mixed
    {
        $previous = $this->database->suppress_errors(true);
        try {
            return $operation();
        } finally {
            $this->database->suppress_errors($previous);
        }
    }
}
