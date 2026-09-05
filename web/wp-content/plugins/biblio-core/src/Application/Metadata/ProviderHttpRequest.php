<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class ProviderHttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $url,
        private array $headers,
        private float $timeoutSeconds,
        private int $maximumResponseBytes
    ) {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts["scheme"] ?? null) !== "https"
            || !isset($parts["host"])
            || isset($parts["user"])
            || isset($parts["pass"])
            || isset($parts["fragment"])
        ) {
            throw new InvalidArgumentException("Provider request URL must be safe HTTPS.");
        }

        if ($timeoutSeconds <= 0 || $timeoutSeconds > 10) {
            throw new InvalidArgumentException("Provider timeout is outside bounds.");
        }

        if ($maximumResponseBytes < 1 || $maximumResponseBytes > 1048576) {
            throw new InvalidArgumentException("Provider response bound is invalid.");
        }
    }

    public function url(): string { return $this->url; }

    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }

    public function timeoutSeconds(): float { return $this->timeoutSeconds; }
    public function maximumResponseBytes(): int { return $this->maximumResponseBytes; }
}
