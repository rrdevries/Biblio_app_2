<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class ProviderHttpResponse
{
    public function __construct(private int $statusCode, private string $body)
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException("Invalid provider HTTP status.");
        }
    }

    public function statusCode(): int { return $this->statusCode; }
    public function body(): string { return $this->body; }
}
