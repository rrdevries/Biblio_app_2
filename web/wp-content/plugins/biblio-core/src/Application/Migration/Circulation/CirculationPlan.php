<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Circulation;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;

/**
 * Source-neutral preservation plan for one stable circulation identity.
 *
 * The canonical payload deliberately remains the exact merged source payload:
 * it is both the immutable observation hash input and the restricted evidence
 * from which a later, separately authorized loan backfill can recover.
 */
final readonly class CirculationPlan implements TypedMigrationPlan
{
    /**
     * @param list<array{book_id:string,record:array<string,mixed>}> $bookOccurrences
     * @param list<array{copy_id:string,book_id:string,record:array<string,mixed>}> $copyOccurrences
     */
    public function __construct(
        private string $sourceId,
        private array $bookOccurrences,
        private array $copyOccurrences
    ) {
        IdentifierConstraints::assertValid(
            $this->sourceId,
            "Circulation source ID"
        );
        if ($this->bookOccurrences === [] && $this->copyOccurrences === []) {
            throw new ValidationException(
                "Circulation plan requires at least one source occurrence."
            );
        }
        foreach ($this->bookOccurrences as $occurrence) {
            $this->assertOccurrence($occurrence, false);
        }
        foreach ($this->copyOccurrences as $occurrence) {
            $this->assertOccurrence($occurrence, true);
        }
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(string $sourceId, array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if (
            $keys !== ["book_occurrences", "copy_occurrences"]
            || !is_array($payload["book_occurrences"])
            || !array_is_list($payload["book_occurrences"])
            || !is_array($payload["copy_occurrences"])
            || !array_is_list($payload["copy_occurrences"])
        ) {
            throw new ValidationException(
                "Circulation source payload has an invalid reviewed shape."
            );
        }

        return new self(
            $sourceId,
            $payload["book_occurrences"],
            $payload["copy_occurrences"]
        );
    }

    public function sourceId(): string { return $this->sourceId; }

    public function hasMaterialLifecycleConflict(): bool
    {
        return count($this->sourceStates()) > 1 || count($this->sourceTypes()) > 1;
    }

    public function sourceState(): string
    {
        $states = $this->sourceStates();
        return count($states) === 1 ? $states[0] : "conflicting";
    }

    /** @return list<string> */
    public function sourceStates(): array
    {
        $states = [];
        foreach ($this->allOccurrences() as $occurrence) {
            $state = $occurrence["record"]["endDate"] === null
                ? "open"
                : "closed";
            $states[$state] = true;
        }
        $values = array_keys($states);
        sort($values, SORT_STRING);
        return $values;
    }

    /** @return list<string> */
    public function sourceTypes(): array
    {
        $types = [];
        foreach ($this->allOccurrences() as $occurrence) {
            $types[$occurrence["record"]["type"]] = true;
        }
        $values = array_keys($types);
        sort($values, SORT_STRING);
        return $values;
    }

    /** @return array<string,mixed> */
    public function restrictedEvidence(
        string $runId,
        string $observationId,
        string $sourceFamily,
        string $sourceType,
        string $sourceSnapshot,
        string $payloadHash
    ): array {
        return [
            "evidence_version" => 1,
            "source" => [
                "run_id" => $runId,
                "observation_id" => $observationId,
                "source_family" => $sourceFamily,
                "source_type" => $sourceType,
                "source_id" => $this->sourceId,
                "source_snapshot" => $sourceSnapshot,
                "payload_hash" => $payloadHash,
            ],
            "classification" => [
                "source_state" => $this->sourceState(),
                "source_states" => $this->sourceStates(),
                "source_types" => $this->sourceTypes(),
                "material_lifecycle_conflict" => $this->hasMaterialLifecycleConflict(),
            ],
            "source_payload" => $this->canonicalPayload(),
        ];
    }

    /** @return array<string,mixed> */
    public function canonicalPayload(): array
    {
        return [
            "book_occurrences" => $this->bookOccurrences,
            "copy_occurrences" => $this->copyOccurrences,
        ];
    }

    /**
     * @param array<string,mixed> $occurrence
     */
    private function assertOccurrence(array $occurrence, bool $copy): void
    {
        $expected = $copy
            ? ["copy_id", "book_id", "record"]
            : ["book_id", "record"];
        $keys = array_keys($occurrence);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new ValidationException(
                "Circulation occurrence has an invalid reviewed shape."
            );
        }
        IdentifierConstraints::assertValid(
            $occurrence["book_id"],
            "Circulation Book source ID"
        );
        if ($copy) {
            IdentifierConstraints::assertValid(
                $occurrence["copy_id"],
                "Circulation Copy source ID"
            );
        }
        if (!is_array($occurrence["record"]) || array_is_list($occurrence["record"])) {
            throw new ValidationException("Circulation source record is invalid.");
        }
        $record = $occurrence["record"];
        $allowed = array_fill_keys(
            ["counterparty", "endDate", "id", "notes", "startDate", "type"],
            true
        );
        foreach (array_keys($record) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new ValidationException(
                    "Circulation source record contains unreviewed evidence."
                );
            }
        }
        foreach (["id", "type", "startDate", "endDate"] as $required) {
            if (!array_key_exists($required, $record)) {
                throw new ValidationException(
                    "Circulation source record lacks required evidence."
                );
            }
        }
        if ($record["id"] !== $this->sourceId) {
            throw new ValidationException(
                "Circulation occurrence identity differs from its observation."
            );
        }
        if (!in_array($record["type"], ["borrowed", "lent_out"], true)) {
            throw new ValidationException("Circulation source type is unsupported.");
        }
        $this->assertDate($record["startDate"], false);
        $this->assertDate($record["endDate"], true);
        foreach (["counterparty", "notes"] as $privateField) {
            if (
                array_key_exists($privateField, $record)
                && $record[$privateField] !== null
                && !is_string($record[$privateField])
            ) {
                throw new ValidationException(
                    "Circulation private source evidence is invalid."
                );
            }
        }
    }

    private function assertDate(mixed $date, bool $nullable): void
    {
        if ($nullable && $date === null) {
            return;
        }
        if (!is_array($date) || array_is_list($date)) {
            throw new ValidationException("Circulation source date is invalid.");
        }
        $keys = array_keys($date);
        sort($keys, SORT_STRING);
        if ($keys !== ["precision", "value"] || !is_string($date["value"])) {
            throw new ValidationException("Circulation source date is invalid.");
        }
        if (!in_array($date["precision"], ["day", "month", "year"], true)) {
            throw new ValidationException(
                "Circulation source date precision is unsupported."
            );
        }
        if (trim($date["value"]) === "" || preg_match('//u', $date["value"]) !== 1) {
            throw new ValidationException("Circulation source date is invalid.");
        }
    }

    /** @return list<array<string,mixed>> */
    private function allOccurrences(): array
    {
        return [...$this->bookOccurrences, ...$this->copyOccurrences];
    }
}
