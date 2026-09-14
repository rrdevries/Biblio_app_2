<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceProfile
{
    /**
     * @param array<string, int> $categoryCounts
     * @param list<string> $unknownCategories
     * @param list<MigrationSourceFinding> $findings
     */
    public function __construct(
        private string $sourceVersion,
        private array $categoryCounts,
        private array $unknownCategories = [],
        private array $findings = []
    ) {
        if (trim($this->sourceVersion) === "" || mb_strlen($this->sourceVersion) > 64) {
            throw new ValidationException("Source version is invalid.");
        }
        foreach ($this->categoryCounts as $category => $count) {
            if ($category === "" || $count < 0) {
                throw new ValidationException("Source category count is invalid.");
            }
        }
    }

    public function sourceVersion(): string { return $this->sourceVersion; }

    /** @return array<string, int> */
    public function categoryCounts(): array
    {
        $counts = $this->categoryCounts;
        ksort($counts, SORT_STRING);
        return $counts;
    }

    /** @return list<string> */
    public function unknownCategories(): array
    {
        $categories = array_values(array_unique($this->unknownCategories));
        sort($categories, SORT_STRING);
        return $categories;
    }

    /** @return list<MigrationSourceFinding> */
    public function findings(): array
    {
        $findings = $this->findings;
        usort($findings, static function (MigrationSourceFinding $a, MigrationSourceFinding $b): int {
            return DeterministicJson::encode($a->toArray()) <=> DeterministicJson::encode($b->toArray());
        });
        return $findings;
    }
}
