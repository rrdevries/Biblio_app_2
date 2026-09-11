<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\ProviderFailureReason;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSearchProvider;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursor;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchProviderFailure;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSearchProvider;
use InvalidArgumentException;

final readonly class ConfigurationErrorBibliographicSearchProvider implements
    BibliographicAuthorSearchProvider,
    BibliographicWorkSearchProvider
{
    public function __construct(private string $providerKey)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException("Invalid metadata provider key.");
        }
    }

    public function key(): string { return $this->providerKey; }

    public function searchAuthors(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicAuthorSearchPage {
        throw new BibliographicSearchProviderFailure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
    }

    public function searchWorks(
        BibliographicTextSearchQuery $query,
        ?BibliographicSearchCursor $cursor = null
    ): BibliographicWorkSearchPage {
        throw new BibliographicSearchProviderFailure(
            ProviderLookupStatus::ConfigurationError,
            ProviderFailureReason::Configuration
        );
    }
}
