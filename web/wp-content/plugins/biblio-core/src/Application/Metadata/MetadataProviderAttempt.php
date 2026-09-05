<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class MetadataProviderAttempt
{
    public function __construct(
        private string $providerKey,
        private ProviderLookupResult $result
    ) {
        if (
            $providerKey === ""
            || strlen($providerKey) > 32
            || preg_match('/^[a-z][a-z0-9_]*$/D', $providerKey) !== 1
        ) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function providerKey(): string { return $this->providerKey; }
    public function result(): ProviderLookupResult { return $this->result; }
}
