<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Read-only observation of protected normal-development state. */
interface RehearsalIsolationGuard
{
    /** @return array<string,mixed> */
    public function fingerprint(): array;
}
