<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Closure;

final readonly class RuntimeMetadataProviderConfiguration
{
    private ?string $openLibraryContactEmail;
    private ?string $googleBooksApiKey;

    public function __construct(
        ?Closure $constantIsDefined = null,
        ?Closure $constantValue = null,
        ?Closure $environmentValue = null
    ) {
        $constantIsDefined ??= static fn (string $name): bool => defined($name);
        $constantValue ??= static fn (string $name): mixed => constant($name);
        $environmentValue ??= static fn (string $name): mixed => getenv($name);

        $this->openLibraryContactEmail = $this->resolve(
            "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL",
            $constantIsDefined,
            $constantValue,
            $environmentValue
        );
        $this->googleBooksApiKey = $this->resolve(
            "GOOGLE_BOOKS_API_KEY",
            $constantIsDefined,
            $constantValue,
            $environmentValue
        );
    }

    public function openLibraryContactEmail(): ?string
    {
        return $this->openLibraryContactEmail;
    }

    public function googleBooksApiKey(): ?string
    {
        return $this->googleBooksApiKey;
    }

    private function resolve(
        string $name,
        Closure $constantIsDefined,
        Closure $constantValue,
        Closure $environmentValue
    ): ?string {
        $value = $constantIsDefined($name)
            ? $constantValue($name)
            : $environmentValue($name);

        return is_string($value) ? $value : null;
    }
}
