<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

final readonly class CoreSchemaHealth
{
    /**
     * @param list<string> $errors
     * @param list<CoreSchemaHealthWarning> $warnings
     */
    public function __construct(
        private array $errors,
        private array $warnings = []
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<CoreSchemaHealthWarning> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function summary(): string
    {
        if (!$this->isHealthy()) {
            return implode("; ", $this->errors);
        }

        if ($this->warnings === []) {
            return "Biblio Core schema is healthy.";
        }

        return "Biblio Core schema is healthy with warnings: " . implode(
            "; ",
            array_map(
                static fn (CoreSchemaHealthWarning $warning): string =>
                    "{$warning->code()}: {$warning->message()}",
                $this->warnings
            )
        );
    }
}
