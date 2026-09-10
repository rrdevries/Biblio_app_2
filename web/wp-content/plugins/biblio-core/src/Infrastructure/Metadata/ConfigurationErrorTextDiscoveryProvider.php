<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderDiscoveryResult;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextDiscoveryProvider;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use InvalidArgumentException;

final readonly class ConfigurationErrorTextDiscoveryProvider implements
    BibliographicTextDiscoveryProvider
{
    public function __construct(private string $providerKey)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function key(): string { return $this->providerKey; }

    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult {
        return BibliographicProviderDiscoveryResult::failure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
    }
}
