<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Reuses the relational observation port, not the disposable identity implementation. */
interface ProductionMigrationTarget extends RehearsalTarget
{
    /** Called only after owner authorization has been verified. Remains blocked on failure. */
    public function blockWrites(): void;
    public function assertWritesBlocked(): void;
    /** Recovery must remain possible even when a failed native restore left tables absent. */
    public function prepareRestore(ProductionAuthorization $authorization): void;
}
