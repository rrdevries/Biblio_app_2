<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata\OpenLibrary;

use InvalidArgumentException;

final readonly class OpenLibraryConfiguration
{
    public function __construct(
        private string $applicationName,
        private string $applicationVersion,
        private string $contactEmail
    ) {
        if (
            preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $applicationName) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $applicationVersion) !== 1
            || strlen($contactEmail) > 254
            || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                "Open Library application/contact configuration is invalid."
            );
        }
    }

    public function userAgent(): string
    {
        return sprintf(
            "%s/%s (mailto:%s)",
            $this->applicationName,
            $this->applicationVersion,
            $this->contactEmail
        );
    }
}
