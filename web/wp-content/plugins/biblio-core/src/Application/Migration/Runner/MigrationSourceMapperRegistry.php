<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceMapperRegistry
{
    /** @var array<string, MigrationSourceMapper> */
    private array $mappers;

    /** @param list<MigrationSourceMapper> $mappers */
    public function __construct(array $mappers)
    {
        $indexed = [];
        foreach ($mappers as $mapper) {
            $adapterId = $mapper->adapterId();
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $adapterId) !== 1) {
                throw new ValidationException("Source mapper adapter ID is invalid.");
            }
            if (isset($indexed[$adapterId])) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::DuplicateMapper,
                    "More than one source mapper owns adapter: {$adapterId}."
                );
            }
            $indexed[$adapterId] = $mapper;
        }
        ksort($indexed, SORT_STRING);
        $this->mappers = $indexed;
    }

    public function forAdapter(string $adapterId): ?MigrationSourceMapper
    {
        return $this->mappers[$adapterId] ?? null;
    }
}
