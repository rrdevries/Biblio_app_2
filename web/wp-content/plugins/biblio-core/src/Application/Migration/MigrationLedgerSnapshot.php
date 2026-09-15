<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationLedgerSnapshot
{
    /**
     * @param list<MigrationLedgerObservation> $observations
     * @param list<MigrationLedgerMappingAnomaly> $mappingAnomalies
     */
    public function __construct(
        private MigrationRun $run,
        private array $observations,
        private array $mappingAnomalies = []
    ) {
    }

    public function run(): MigrationRun { return $this->run; }
    /** @return list<MigrationLedgerObservation> */
    public function observations(): array { return $this->observations; }
    /** @return list<MigrationLedgerMappingAnomaly> */
    public function mappingAnomalies(): array { return $this->mappingAnomalies; }
}
