<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use InvalidArgumentException;

final readonly class ConfigurationErrorMetadataProvider implements MetadataProvider
{
    public function __construct(private string $providerKey)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function key(): string { return $this->providerKey; }

    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        return ProviderLookupResult::configurationError();
    }
}
