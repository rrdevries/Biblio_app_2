<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Both endpoints are positively identified disposable databases. */
interface RehearsalDatabaseTransport
{
    public function databaseId(): string;
    public function export(string $path): void;
    public function import(string $path): void;
    /**
 * @return array<string,mixed>
 */
    public function fingerprint(): array;
}
