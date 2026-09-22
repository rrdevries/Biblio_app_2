<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

interface RehearsalEvidenceStore
{
    /**
 * @param array<string,mixed> $payload
 * @return array{sha256:string,receipt_id:string}
 */
    public function append(string $kind, array $payload): array;

    /**
     * @param array{sha256:string,receipt_id:string} $reference
     * @return array<string,mixed>
     */
    public function read(array $reference): array;
}
