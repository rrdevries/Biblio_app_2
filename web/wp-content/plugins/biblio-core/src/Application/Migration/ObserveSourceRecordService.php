<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;

final readonly class ObserveSourceRecordService
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private MigrationClock $clock
    ) {
    }

    /** @param array<string, mixed>|null $payload */
    public function observe(
        MigrationRun $run,
        string $sourceType,
        string $sourceId,
        string $payloadHash,
        ?array $payload = null,
        ?string $payloadReference = null
    ): SourceObservation {
        if ($run->status() !== MigrationRunStatus::Running) {
            throw new ValidationException("Migration run is not running.");
        }
        $observation = SourceObservation::observe(
            $run,
            $sourceType,
            $sourceId,
            $payloadHash,
            $payload,
            $payloadReference,
            $this->clock->now()
        );

        if ($run->mode() === MigrationMode::DryRun) {
            return $observation;
        }

        return $this->ledger->addOrFindObservation($observation);
    }
}
