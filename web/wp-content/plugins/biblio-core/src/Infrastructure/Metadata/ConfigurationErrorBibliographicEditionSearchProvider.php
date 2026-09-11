<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicExternalEditionSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkReference;
use InvalidArgumentException;

final readonly class ConfigurationErrorBibliographicEditionSearchProvider implements
    BibliographicExternalEditionSearchProvider
{
    public function __construct(private string $providerKey)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function key(): string { return $this->providerKey; }

    public function searchEditionsForProviderWork(
        BibliographicWorkReference $parentWork,
        BibliographicProviderEntityIdentity $providerWork,
        int $offset,
        int $limit
    ): BibliographicEditionProviderPage {
        throw new BibliographicSearchProviderFailure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
    }
}
