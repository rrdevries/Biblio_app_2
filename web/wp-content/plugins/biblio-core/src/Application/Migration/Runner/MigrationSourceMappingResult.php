<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceMappingResult
{
    /**
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    public function __construct(private array $records, private array $findings)
    {
        $identities = [];
        foreach ($this->records as $record) {
            $key = $record->sourceType() . "\0" . $record->sourceId();
            if (isset($identities[$key])) {
                throw new ValidationException(
                    "Source mapping produced a duplicate logical source identity."
                );
            }
            $identities[$key] = true;
        }
    }

    /** @return list<MigrationSourceRecord> */
    public function records(): array
    {
        $records = $this->records;
        usort($records, static fn (MigrationSourceRecord $a, MigrationSourceRecord $b): int =>
            [$a->sourceType(), $a->sourceId(), $a->payloadHash()]
                <=> [$b->sourceType(), $b->sourceId(), $b->payloadHash()]);
        return $records;
    }

    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array
    {
        $findings = $this->findings;
        usort($findings, static fn (
            MigrationSourceMappingFinding $a,
            MigrationSourceMappingFinding $b
        ): int => [
            $a->sourceType(),
            $a->sourceId(),
            $a->disposition()->value,
            $a->reasonCode(),
        ] <=> [
            $b->sourceType(),
            $b->sourceId(),
            $b->disposition()->value,
            $b->reasonCode(),
        ]);
        return $findings;
    }

    /** @param list<MigrationSourceRecord> $records */
    public static function passthrough(array $records): self
    {
        return new self($records, []);
    }
}
