<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationMappingContractRegistry
{
    /** @var array<string, MigrationMappingContract> */
    private array $contracts;

    /** @param list<MigrationMappingContract> $contracts */
    public function __construct(array $contracts)
    {
        $indexed = [];
        foreach ($contracts as $contract) {
            if (isset($indexed[$contract->sourceType()])) {
                throw new ValidationException("Duplicate reconciliation source contract.");
            }
            $indexed[$contract->sourceType()] = $contract;
        }
        ksort($indexed, SORT_STRING);
        $this->contracts = $indexed;
    }

    public function forSourceType(string $sourceType): ?MigrationMappingContract
    {
        return $this->contracts[$sourceType] ?? null;
    }
}
