<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkProviderPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use InvalidArgumentException;

final readonly class ConfigurationErrorBibliographicAuthorWorkSearchProvider implements
    BibliographicAuthorWorkSearchProvider
{
    public function __construct(private string $providerKey)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function key(): string { return $this->providerKey; }

    public function searchWorksForAuthor(
        BibliographicAuthorReference $author,
        int $offset,
        int $limit
    ): BibliographicAuthorWorkProviderPage {
        throw new BibliographicSearchProviderFailure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
    }
}
