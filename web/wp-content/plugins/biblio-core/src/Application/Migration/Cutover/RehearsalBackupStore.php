<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

interface RehearsalBackupStore
{
    /**
 * Create immutable full DB backup, verify checksum and independent restore.
 * @param array<string,mixed> $binding
 * @return array<string,mixed>
 */
    public function create(string $phase, array $binding): array;
    /**
 * Revalidate receipt and checksum before apply or restore.
 * @param array<string,mixed> $receipt
 * @param array<string,mixed> $binding
 */
    public function verify(array $receipt, array $binding): void;
    /**
 * Restore PRE backup and prove exact full database baseline.
 * @param array<string,mixed> $receipt
 * @param array<string,mixed> $binding
 */
    public function restore(array $receipt, array $binding): void;
}
