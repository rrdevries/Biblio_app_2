<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use RuntimeException;

final class RestRequestException extends RuntimeException
{
    private function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function missing(string $field): self
    {
        return new self(
            "biblio_missing_required_field",
            "Required field '{$field}' is missing."
        );
    }

    public static function wrongType(string $field, string $type): self
    {
        return new self(
            "biblio_invalid_field_type",
            "Field '{$field}' must be {$type}."
        );
    }

    public static function invalid(string $field): self
    {
        return new self(
            "biblio_invalid_field_syntax",
            "Field '{$field}' has invalid syntax."
        );
    }

    public static function unknownFields(): self
    {
        return new self(
            "biblio_unknown_request_fields",
            "The request contains unsupported fields."
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
