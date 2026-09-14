<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceAdapterRegistry
{
    /** @var array<string, MigrationSourceAdapter> */
    private array $adapters;

    /** @param list<MigrationSourceAdapter> $adapters */
    public function __construct(array $adapters)
    {
        $indexed = [];
        foreach ($adapters as $adapter) {
            $id = $adapter->adapterId();
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) !== 1) {
                throw new ValidationException("Source adapter ID is invalid.");
            }
            if (isset($indexed[$id])) {
                throw new ValidationException("Duplicate source adapter: {$id}.");
            }
            $indexed[$id] = $adapter;
        }
        ksort($indexed, SORT_STRING);
        $this->adapters = $indexed;
    }

    public function get(string $adapterId): MigrationSourceAdapter
    {
        $adapter = $this->adapters[$adapterId] ?? null;
        if (!$adapter instanceof MigrationSourceAdapter) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedAdapter,
                "Source adapter is not registered: {$adapterId}."
            );
        }
        return $adapter;
    }
}
