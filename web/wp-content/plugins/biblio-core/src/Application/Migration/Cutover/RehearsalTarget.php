<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Implementations acquire exclusive operator access before any rehearsal work. */
interface RehearsalTarget
{
    /**
 * Validate actual marker, negative guards, code, runtime and explicit identity.
 * @return array<string,mixed>
 */
    public function identity(): array;
    /**
 * Exact full database state, all 57 Biblio tables and row counts; no bodies.
 * @return array<string,mixed>
 */
    public function fingerprint(): array;
    public function assertEmpty(): void;
    /** Hold a nonblocking exclusive operation lock until release. */
    public function acquire(): void;
    public function release(): void;
}
