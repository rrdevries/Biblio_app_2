<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use InvalidArgumentException;

final readonly class CoreSchemaHealthWarning
{
    /**
     * @param array<string, bool|float|int|string|null|list<bool|float|int|string|null>> $context
     */
    public function __construct(
        private string $code,
        private string $message,
        private array $context = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $this->code) !== 1) {
            throw new InvalidArgumentException(
                "Schema-health warning code must be a lowercase identifier."
            );
        }

        if (trim($this->message) === "") {
            throw new InvalidArgumentException(
                "Schema-health warning message must not be empty."
            );
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, bool|float|int|string|null|list<bool|float|int|string|null>>
     */
    public function context(): array
    {
        return $this->context;
    }
}
