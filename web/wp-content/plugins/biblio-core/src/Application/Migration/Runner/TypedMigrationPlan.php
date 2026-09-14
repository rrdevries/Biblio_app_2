<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

/**
 * Source adapters may attach an already-reviewed, source-neutral V2 plan.
 * The canonical payload remains the hashing/evidence representation; domain
 * participants consume the typed object rather than reinterpreting source JSON.
 */
interface TypedMigrationPlan
{
    /** @return array<string, mixed> */
    public function canonicalPayload(): array;
}
