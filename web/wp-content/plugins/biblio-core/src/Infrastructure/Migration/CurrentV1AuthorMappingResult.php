<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Runner\{
    MigrationSourceMappingFinding,
    MigrationSourceRecord
};

final readonly class CurrentV1AuthorMappingResult
{
    /**
     * @param list<MigrationSourceRecord> $authorPlans
     * @param list<MigrationSourceRecord> $contributorPlans
     * @param list<MigrationSourceMappingFinding> $findings
     */
    public function __construct(
        private array $authorPlans,
        private array $contributorPlans,
        private array $findings
    ) {
    }

    /** @return list<MigrationSourceRecord> */
    public function authorPlans(): array { return $this->authorPlans; }
    /** @return list<MigrationSourceRecord> */
    public function contributorPlans(): array { return $this->contributorPlans; }
    /** @return list<MigrationSourceRecord> */
    public function records(): array
    {
        return [...$this->authorPlans, ...$this->contributorPlans];
    }
    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array { return $this->findings; }
    /** @return list<MigrationSourceMappingFinding> */
    public function preservationFindings(): array
    {
        return $this->withDisposition(MigrationDisposition::PreservedDeferred);
    }
    /** @return list<MigrationSourceMappingFinding> */
    public function quarantineFindings(): array
    {
        return $this->withDisposition(MigrationDisposition::Quarantined);
    }
    /** @return list<MigrationSourceMappingFinding> */
    public function blockedDependencies(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (MigrationSourceMappingFinding $finding): bool =>
                $finding->reasonCode()
                    === CurrentV1AuthorMappingReason::CatalogWorkUnavailable->value
        ));
    }
    /** @return list<MigrationSourceMappingFinding> */
    public function convergences(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (MigrationSourceMappingFinding $finding): bool =>
                $finding->reasonCode()
                    === CurrentV1AuthorMappingReason::DuplicateAuthorEdgeConvergence->value
        ));
    }

    /** @return list<MigrationSourceMappingFinding> */
    private function withDisposition(MigrationDisposition $disposition): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (MigrationSourceMappingFinding $finding): bool =>
                $finding->disposition() === $disposition
        ));
    }
}
