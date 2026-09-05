<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use LogicException;

final readonly class ProviderHttpResult
{
    private function __construct(
        private ProviderHttpResultStatus $status,
        private ?ProviderHttpResponse $response
    ) {
    }

    public static function response(ProviderHttpResponse $response): self
    {
        return new self(ProviderHttpResultStatus::Response, $response);
    }

    public static function timeout(): self
    {
        return new self(ProviderHttpResultStatus::Timeout, null);
    }

    public static function networkFailure(): self
    {
        return new self(ProviderHttpResultStatus::NetworkFailure, null);
    }

    public function status(): ProviderHttpResultStatus { return $this->status; }

    public function requireResponse(): ProviderHttpResponse
    {
        if ($this->response === null) {
            throw new LogicException("Provider HTTP result has no response.");
        }

        return $this->response;
    }
}
