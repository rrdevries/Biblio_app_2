<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

final readonly class CoreSchemaHealth
{
    /** @param list<string> $issues */
    public function __construct(private array $issues)
    {
    }

    public function isHealthy(): bool
    {
        return $this->issues === [];
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function summary(): string
    {
        return $this->isHealthy()
            ? "Biblio Core schema is healthy."
            : implode("; ", $this->issues);
    }
}
