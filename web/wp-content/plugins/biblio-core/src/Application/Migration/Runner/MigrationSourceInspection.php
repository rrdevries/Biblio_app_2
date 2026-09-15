<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class MigrationSourceInspection
{
    /**
     * @param list<MigrationSourceRecord> $records
     * @param array<string, int> $typeCounts
     */
    public function __construct(
        private MigrationSourcePackage $package,
        private MigrationSourceAdapter $adapter,
        private MigrationSourceProfile $profile,
        private array $records,
        private array $typeCounts
    ) {
    }

    public function package(): MigrationSourcePackage { return $this->package; }
    public function adapter(): MigrationSourceAdapter { return $this->adapter; }
    public function profile(): MigrationSourceProfile { return $this->profile; }
    /** @return list<MigrationSourceRecord> */
    public function records(): array { return $this->records; }
    /** @return array<string, int> */
    public function typeCounts(): array { return $this->typeCounts; }

    /** @return array<string, mixed> */
    public function sourcePayload(): array
    {
        $findings = array_map(
            static fn (MigrationSourceFinding $finding): array => $finding->toArray(),
            $this->profile->findings()
        );
        $findingCounts = [
            "malformed_record" => 0,
            "unreadable_record" => 0,
        ];
        foreach ($findings as $finding) {
            $reason = $finding["reason_code"];
            $findingCounts[$reason] = ($findingCounts[$reason] ?? 0) + 1;
        }
        ksort($findingCounts, SORT_STRING);
        $categoryStrategies = [];
        foreach ($this->profile->categoryStrategies() as $strategy) {
            $categoryStrategies[$strategy->category()] = $strategy->toArray();
        }

        return [
            "package" => $this->package->toArray(),
            "adapter_id" => $this->adapter->adapterId(),
            "source_family" => $this->adapter->sourceFamily(),
            "source_version" => $this->profile->sourceVersion(),
            "category_counts" => $this->profile->categoryCounts(),
            "category_strategies" => $categoryStrategies,
            "source_type_counts" => $this->typeCounts,
            "unknown_categories" => $this->profile->unknownCategories(),
            "finding_counts" => $findingCounts,
            "findings" => $findings,
        ];
    }
}
